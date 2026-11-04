"""
AI Microservice for Resume Analysis, ATS Scoring, and Smart Job Matching.
Run via: uvicorn app:app --host 127.0.0.1 --port 8000 --reload
"""

import os
import sys
import re
import json
import io
import requests

# Ensure the module directory is in sys.path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import fitz  # PyMuPDF
import docx
from fastapi import FastAPI, UploadFile, File, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

from common import db, find_skills, COURSES, get_curated_courses

app = FastAPI(
    title="AI Resume Analyzer & Job Matcher Service",
    description="Microservice providing automated ATS resume evaluation, skill parsing, and TF-IDF semantic job matching.",
    version="2.0.0"
)

# Enable CORS for local web interactions
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

def extract_text(filename: str, data: bytes) -> str:
    """Extract clean plain text from PDF or DOCX file bytes."""
    name = (filename or "").lower()
    text_chunks = []

    if name.endswith(".pdf"):
        try:
            with fitz.open(stream=data, filetype="pdf") as doc:
                for page in doc:
                    page_text = page.get_text()
                    if page_text:
                        text_chunks.append(page_text)
        except Exception as e:
            raise HTTPException(400, f"Error parsing PDF document: {str(e)}")

    elif name.endswith(".docx"):
        try:
            document = docx.Document(io.BytesIO(data))
            # Extract paragraphs
            for p in document.paragraphs:
                if p.text and p.text.strip():
                    text_chunks.append(p.text.strip())
            # Extract table cells (frequently used for skills/education in Word resumes)
            for table in document.tables:
                for row in table.rows:
                    row_cells = [cell.text.strip() for cell in row.cells if cell.text.strip()]
                    if row_cells:
                        text_chunks.append(" | ".join(row_cells))
        except Exception as e:
            raise HTTPException(400, f"Error parsing DOCX document: {str(e)}")
    else:
        raise HTTPException(400, "Unsupported file format. Please upload a PDF or DOCX file.")

    raw_text = "\n".join(text_chunks)
    # Clean up excess whitespace and normalize bullet symbols
    cleaned = re.sub(r"[\r\t\f\v ]+", " ", raw_text)
    cleaned = re.sub(r"\n\s*\n+", "\n\n", cleaned)
    return cleaned.strip()

def calculate_ats_score(text: str) -> tuple[int, list[str], list[str], dict]:
    """
    Evaluate resume text against ATS criteria:
    - Contact Information (15 pts)
    - Essential Sections (35 pts)
    - Technical Skill Density (25 pts)
    - Action Verbs & Quantifiable Results (15 pts)
    - Formatting & Length (10 pts)
    """
    t = text.lower()
    missing_sections = []
    suggestions = []
    breakdown = {
        "contact": 0,
        "sections": 0,
        "skills": 0,
        "impact": 0,
        "format": 0
    }

    # 1. Contact Info Checks (Max 15 pts)
    has_email = bool(re.search(r"[\w.+-]+@[\w-]+\.[a-zA-Z]{2,}", t))
    has_phone = bool(re.search(r"(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}|\+?\d[\d\s-]{8,}\d", t))
    has_links = bool(re.search(r"github\.com|linkedin\.com|gitlab\.com|portfolio", t))

    if has_email:
        breakdown["contact"] += 6
    else:
        missing_sections.append("email")
        suggestions.append("Add a professional email address at the top.")

    if has_phone:
        breakdown["contact"] += 5
    else:
        missing_sections.append("phone")
        suggestions.append("Include an active phone number.")

    if has_links:
        breakdown["contact"] += 4
    else:
        suggestions.append("Include your LinkedIn profile or GitHub / portfolio link.")

    # 2. Key Sections Checks (Max 35 pts)
    sections = [
        ("education", r"\b(education|academics|degree|university|b\.?tech|b\.?e\b|bca|mca|b\.?sc|m\.?tech)\b", 8, "Add a dedicated Education section."),
        ("experience", r"\b(experience|employment|work history|internship|internships|work experience)\b", 10, "Include an Experience or Internship section with dates and responsibilities."),
        ("projects", r"\b(projects?|academic projects?|personal projects?)\b", 9, "List 2-3 technical Projects demonstrating your practical abilities."),
        ("skills", r"\b(skills|technical skills|technologies|tools|competencies)\b", 5, "Add a distinct 'Technical Skills' section for rapid scanning."),
        ("certifications", r"\b(certifications?|certificates?|licenses?|courses?)\b", 3, "Mention relevant certifications or professional coursework.")
    ]

    for key, regex, pts, tip in sections:
        if re.search(regex, t):
            breakdown["sections"] += pts
        else:
            missing_sections.append(key)
            suggestions.append(tip)

    # 3. Technical Skills Evaluation (Max 25 pts)
    detected_skills = find_skills(text)
    n_skills = len(detected_skills)
    
    # 2.5 points per skill up to 25 points (10 skills)
    breakdown["skills"] = min(25, int(n_skills * 2.5))
    if n_skills < 4:
        suggestions.append("Incorporate more industry-standard technical skills relevant to your target domain.")
    elif n_skills < 7:
        suggestions.append("Consider highlighting additional relevant frameworks, databases, or cloud tools.")

    # 4. Impact, Action Verbs & Metrics (Max 15 pts)
    action_verbs = r"\b(developed|built|implemented|designed|architected|engineered|created|led|optimized|reduced|increased|automated|deployed|resolved|launched)\b"
    action_matches = len(re.findall(action_verbs, t))
    if action_matches >= 4:
        breakdown["impact"] += 8
    elif action_matches >= 1:
        breakdown["impact"] += 4
        suggestions.append("Use more strong action verbs (e.g., 'Engineered', 'Optimized', 'Automated') to begin bullet points.")
    else:
        suggestions.append("Start your achievement bullet points with strong action verbs rather than passive descriptions.")

    # Quantifiable metrics check (numbers, percentages, scales)
    has_metrics = bool(re.search(r"\b(\d+%\b|\$\d+|\b\d+x\b|\b\d+\+?\s*(users|clients|requests|ms|seconds|hours|queries|endpoints))", t))
    if has_metrics:
        breakdown["impact"] += 7
    else:
        suggestions.append("Quantify your achievements with numbers or percentages (e.g., 'Improved API response by 35%').")

    # 5. Formatting & Length (Max 10 pts)
    word_count = len(text.split())
    if 300 <= word_count <= 950:
        breakdown["format"] = 10
    elif 180 <= word_count < 300:
        breakdown["format"] = 6
        suggestions.append("Your resume appears slightly short. Aim for 350-700 words detailing your projects and roles.")
    elif word_count > 950:
        breakdown["format"] = 6
        suggestions.append("Your resume may be too verbose. Try keeping it concise (typically 1-2 pages).")
    else:
        breakdown["format"] = 2
        suggestions.append("Resume contains very little text. Ensure proper content formatting.")

    total_score = sum(breakdown.values())
    total_score = max(5, min(total_score, 100))

    return total_score, missing_sections, suggestions, breakdown

def get_match_label(score: int) -> str:
    """Return categorical rating badge label for a match score."""
    if score >= 80:
        return "Strong Match"
    elif score >= 65:
        return "Good Match"
    elif score >= 45:
        return "Partial Match"
    else:
        return "Low Match"

@app.get("/")
def root():
    return {
        "service": "AI Resume & Job Matcher API",
        "status": "online",
        "version": "2.0.0",
        "endpoints": ["/analyze", "/match/{resume_id}", "/match-all", "/collect-jobs", "/health"]
    }

@app.get("/health")
def health():
    """Verify API status and database connectivity."""
    db_ok = False
    error_msg = None
    try:
        con = db()
        cur = con.cursor()
        cur.execute("SELECT 1")
        cur.fetchone()
        con.close()
        db_ok = True
    except Exception as e:
        error_msg = str(e)

    return {
        "status": "healthy" if db_ok else "degraded",
        "database_connected": db_ok,
        "database_error": error_msg
    }

def analyze_with_gemini(text: str) -> dict:
    """
    Optional LLM analysis using Google Gemini API.
    Returns executive summary, key strengths, and tailored recommendations.
    """
    api_key = os.getenv("GEMINI_API_KEY") or os.getenv("GOOGLE_API_KEY")
    if not api_key:
        return {}

    candidate_models = ["gemini-3.5-flash", "gemini-3.8-flash", "gemini-flash-latest"]
    prompt = (
        "You are an expert technical career advisor and senior recruiter.\n"
        "Analyze the following resume text and return a JSON object with:\n"
        "1. 'summary': A crisp 2-3 sentence executive profile summary.\n"
        "2. 'strengths': A list of 3-4 top professional strengths found.\n"
        "3. 'recommendations': A list of 3 actionable career or resume improvement recommendations.\n\n"
        f"Resume text:\n{text[:4500]}\n\n"
        "Respond ONLY with valid JSON with keys: summary, strengths, recommendations."
    )
    payload = {
        "contents": [{"parts": [{"text": prompt}]}],
        "generationConfig": {"temperature": 0.2, "responseMimeType": "application/json"}
    }

    for model in candidate_models:
        try:
            url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}"
            res = requests.post(url, json=payload, timeout=8)
            if res.status_code == 200:
                data = res.json()
                raw_text = data.get("candidates", [{}])[0].get("content", {}).get("parts", [{}])[0].get("text", "")
                if raw_text:
                    return json.loads(raw_text)
        except Exception:
            continue

    return {}

@app.post("/analyze")
async def analyze_resume(file: UploadFile = File(...)):
    """
    Parse uploaded resume document, calculate ATS score and breakdown,
    detect technical skills, and provide actionable suggestions.
    """
    data = await file.read()
    if len(data) > 6 * 1024 * 1024:
        raise HTTPException(400, "Uploaded file exceeds maximum limit of 6 MB.")

    text = extract_text(file.filename, data)
    if len(text.strip()) < 50:
        raise HTTPException(400, "Could not extract legible text. If this is a scanned document image, please use a text-based PDF or DOCX.")

    score, missing, tips, breakdown = calculate_ats_score(text)
    detected_skills = find_skills(text)
    gemini_insights = analyze_with_gemini(text)

    return {
        "text": text,
        "skills": detected_skills,
        "ats_score": score,
        "breakdown": breakdown,
        "missing_sections": missing,
        "suggestions": tips,
        "gemini_insights": gemini_insights,
        "word_count": len(text.split()),
        "note": "Estimated ATS-readiness rating based on industry formatting standards."
    }

@app.post("/match/{resume_id}")
def match_resume(resume_id: int):
    """
    Compare resume against all active job vacancies using:
    - 60% Skill overlap weight
    - 40% TF-IDF Cosine text similarity
    Stores results in `job_matches` table.
    """
    try:
        con = db()
        cur = con.cursor(dictionary=True)
    except Exception as e:
        raise HTTPException(500, f"Database connection error: {str(e)}")

    cur.execute("SELECT text_content FROM resumes WHERE id = %s", (resume_id,))
    resume_row = cur.fetchone()
    if not resume_row:
        con.close()
        raise HTTPException(404, f"Resume with ID {resume_id} not found.")

    resume_text = resume_row["text_content"] or ""
    candidate_skills = set(find_skills(resume_text))

    cur.execute("SELECT id, title, description, skills_json FROM job_vacancies WHERE is_active = 1")
    jobs = cur.fetchall()

    if not jobs:
        con.close()
        return {
            "matched": 0,
            "message": "No active jobs found in database. Run job_collector.py or trigger via /collect-jobs."
        }

    # Vectorize candidate resume text and job postings
    corpus = [resume_text] + [f"{j['title']} {j.get('description') or ''}" for j in jobs]
    try:
        vec = TfidfVectorizer(stop_words="english", max_features=5000, ngram_range=(1, 2))
        tfidf_matrix = vec.fit_transform(corpus)
        sims = cosine_similarity(tfidf_matrix[0:1], tfidf_matrix[1:])[0]
    except Exception:
        # Fallback if vocabulary is too sparse
        sims = [0.1] * len(jobs)

    write_conn = db()
    write_cur = write_conn.cursor()

    matched_count = 0
    for job, sim in zip(jobs, sims):
        raw_skills = job.get("skills_json")
        try:
            required_skills = set(json.loads(raw_skills or "[]"))
        except Exception:
            required_skills = set()

        hits = sorted(candidate_skills & required_skills)
        missing = sorted(required_skills - candidate_skills)

        # Skill overlap score
        if required_skills:
            skill_score = len(hits) / len(required_skills)
        else:
            skill_score = float(sim)

        # Combined formula: 60% skills + 40% text semantic similarity
        # Scale TF-IDF appropriately (typical cosine similarity between resume and job is 0.15 - 0.5)
        normalized_sim = min(1.0, float(sim) * 2.2)
        final_score = int(round(100 * (0.60 * skill_score + 0.40 * normalized_sim)))
        final_score = max(5, min(final_score, 99))  # Clamp between 5 and 99

        match_label = get_match_label(final_score)

        # Prepare course recommendations for missing skills (up to 5)
        courses_map = {}
        for skill in missing[:5]:
            courses_map[skill] = get_curated_courses(skill)

        write_cur.execute(
            """
            INSERT INTO job_matches (resume_id, job_id, score, label, matching_json, missing_json, courses_json)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                label = VALUES(label),
                matching_json = VALUES(matching_json),
                missing_json = VALUES(missing_json),
                courses_json = VALUES(courses_json)
            """,
            (
                resume_id,
                job["id"],
                final_score,
                match_label,
                json.dumps(hits),
                json.dumps(missing),
                json.dumps(courses_map)
            )
        )
        matched_count += 1

    write_conn.commit()
    write_conn.close()
    con.close()

    return {
        "status": "success",
        "matched": matched_count,
        "resume_id": resume_id,
        "formula": "60% Technical Skill Overlap + 40% TF-IDF Cosine Similarity"
    }

@app.post("/match-all")
def match_all_resumes():
    """Batch re-match all resumes against active jobs."""
    try:
        con = db()
        cur = con.cursor(dictionary=True)
        cur.execute("SELECT id FROM resumes")
        resumes = cur.fetchall()
        con.close()
    except Exception as e:
        raise HTTPException(500, f"Database error: {str(e)}")

    results = []
    for r in resumes:
        res = match_resume(r["id"])
        results.append({"resume_id": r["id"], "status": res.get("status")})

    return {"processed_resumes": len(results), "details": results}

@app.post("/collect-jobs")
def trigger_job_collector(background_tasks: BackgroundTasks):
    """Trigger the job collection pipeline in the background."""
    import job_collector

    def run_pipeline():
        try:
            job_collector.run()
        except Exception as e:
            print(f"Error in background job collector: {e}")

    background_tasks.add_task(run_pipeline)
    return {
        "status": "triggered",
        "message": "Job collector pipeline started in background. Sources will update shortly."
    }
