"""
Job Collector Service: Aggregates real-time tech jobs from multiple free job APIs.
Run standalone via: python job_collector.py
Or invoked periodically via APScheduler / FastAPI /collect-jobs endpoint.
"""

import os
import sys
import re
import json
import html
import requests
import datetime as dt

# Ensure the module directory is in sys.path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from common import db, find_skills

QUERIES = [
    "python developer", "java developer", "software developer", "full stack developer",
    "web developer", "frontend developer", "data analyst", "machine learning",
    "devops engineer", "cloud engineer", "cybersecurity", "qa engineer"
]

def clean_html(raw_html: str) -> str:
    """Strip HTML markup and decode entities from job descriptions."""
    if not raw_html:
        return ""
    clean_text = re.sub(r"<[^>]+>", " ", raw_html)
    clean_text = html.unescape(clean_text)
    clean_text = re.sub(r"\s+", " ", clean_text)
    return clean_text.strip()

def save_job(cur, source: str, ext_id: str, company: str, title: str, desc: str, loc: str, remote: int, url: str, posted: str):
    """Insert or update a job vacancy record in the database."""
    cleaned_desc = clean_html(desc)
    skills = find_skills(f"{title} {cleaned_desc}")

    cur.execute(
        """
        INSERT INTO job_vacancies 
          (source, external_id, company, title, description, skills_json, location, is_remote, apply_url, posted_at, is_active)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1)
        ON DUPLICATE KEY UPDATE 
          company = VALUES(company),
          title = VALUES(title),
          description = VALUES(description),
          skills_json = VALUES(skills_json),
          location = VALUES(location),
          is_remote = VALUES(is_remote),
          apply_url = VALUES(apply_url),
          is_active = 1
        """,
        (
            source,
            str(ext_id),
            (company or "Confidential")[:150],
            (title or "Software Developer")[:255],
            cleaned_desc,
            json.dumps(skills),
            (loc or "Remote")[:150],
            int(remote),
            (url or "#")[:600],
            posted
        )
    )

def collect_adzuna(cur) -> str:
    """Fetch jobs from the Adzuna API if keys are provided in .env."""
    app_id = os.getenv("ADZUNA_APP_ID")
    app_key = os.getenv("ADZUNA_APP_KEY")

    if not app_id or not app_key:
        return "Skipped (ADZUNA_APP_ID / ADZUNA_APP_KEY not set in .env)"

    count = 0
    # Search for top queries
    for q in QUERIES[:5]:
        try:
            resp = requests.get(
                "https://api.adzuna.com/v1/api/jobs/in/search/1",
                params={
                    "app_id": app_id,
                    "app_key": app_key,
                    "what": q,
                    "results_per_page": 20,
                    "content-type": "application/json"
                },
                timeout=20
            )
            if resp.status_code == 200:
                data = resp.json()
                for item in data.get("results", []):
                    created = item.get("created", "")[:19].replace("T", " ") or None
                    company = item.get("company", {}).get("display_name", "Tech Employer")
                    loc = item.get("location", {}).get("display_name", "India")
                    save_job(
                        cur,
                        "adzuna",
                        item["id"],
                        company,
                        item.get("title", q),
                        item.get("description", ""),
                        loc,
                        0,
                        item.get("redirect_url", ""),
                        created
                    )
                    count += 1
        except Exception as e:
            print(f"[Adzuna] query '{q}' error: {e}")

    return f"Success ({count} jobs collected)"

def collect_remotive(cur) -> str:
    """Fetch free tech & developer listings from the Remotive API."""
    count = 0
    categories = ["software-dev", "data", "devops"]
    for cat in categories:
        try:
            resp = requests.get(
                "https://remotive.com/api/remote-jobs",
                params={"category": cat, "limit": 25},
                timeout=25
            )
            if resp.status_code == 200:
                data = resp.json()
                for job in data.get("jobs", []):
                    pub_date = job.get("publication_date", "")[:19].replace("T", " ") or None
                    save_job(
                        cur,
                        "remotive",
                        job.get("id"),
                        job.get("company_name", "Remote Company"),
                        job.get("title", "Remote Tech Role"),
                        job.get("description", ""),
                        job.get("candidate_required_location", "Worldwide Remote"),
                        1,
                        job.get("url", "https://remotive.com"),
                        pub_date
                    )
                    count += 1
        except Exception as e:
            print(f"[Remotive] category '{cat}' error: {e}")

    return f"Success ({count} jobs collected)"

def collect_arbeitnow(cur) -> str:
    """Fetch tech listings from the Arbeitnow Job Board API."""
    count = 0
    try:
        resp = requests.get("https://www.arbeitnow.com/api/job-board-api", timeout=25)
        if resp.status_code == 200:
            data = resp.json()
            for job in data.get("data", []):
                created_ts = job.get("created_at")
                if created_ts:
                    posted_date = dt.datetime.fromtimestamp(created_ts).strftime("%Y-%m-%d %H:%M:%S")
                else:
                    posted_date = dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

                save_job(
                    cur,
                    "arbeitnow",
                    job.get("slug") or str(job.get("id", "")),
                    job.get("company_name", "Global Employer"),
                    job.get("title", "Software Engineer"),
                    job.get("description", ""),
                    job.get("location", "Remote / Hybrid"),
                    1 if job.get("remote") else 0,
                    job.get("url", "https://www.arbeitnow.com"),
                    posted_date
                )
                count += 1
    except Exception as e:
        return f"Error: {str(e)[:100]}"

    return f"Success ({count} jobs collected)"

def seed_fallback_jobs(cur):
    """Ensure baseline realistic jobs exist even when offline or APIs are rate-limited."""
    sample_jobs = [
        ("Python / Django Full Stack Engineer", "NextWave Technologies", 
         "Building SaaS products using Python, Django, PostgreSQL, React, and REST APIs. Experience with Docker, Git, and Celery is beneficial.",
         ["python", "django", "postgresql", "react", "rest api", "docker", "git"], "Remote", 1),
        ("React & Frontend Developer", "PixelCraft Studios", 
         "Develop modern web user interfaces using React, TypeScript, HTML5, CSS3, Tailwind CSS, and Redux. Knowledge of REST APIs and responsive design required.",
         ["react", "typescript", "javascript", "html", "css", "tailwind css", "redux", "rest api"], "Bengaluru, India", 0),
        ("Data Scientist / ML Engineer", "Acuity AI Labs", 
         "Analyze complex datasets and train machine learning models using Python, Scikit-Learn, PyTorch, Pandas, and NumPy. Experience in NLP and Tableau is preferred.",
         ["python", "machine learning", "pytorch", "scikit-learn", "pandas", "numpy", "nlp", "tableau"], "Hyderabad, India", 0),
        ("Java Spring Boot Developer", "Enterprise Core", 
         "Architecting enterprise microservices using Java, Spring Boot, MySQL, Hibernate, and Apache Kafka. Understanding of Docker, Kubernetes, and CI/CD pipelines.",
         ["java", "spring", "mysql", "microservices", "docker", "kubernetes", "ci/cd"], "Pune, India", 0),
        ("DevOps & Cloud Associate", "CloudInfra Pro", 
         "Manage AWS and Linux cloud infrastructure, build Jenkins CI/CD automation pipelines, maintain Docker containers and Kubernetes clusters.",
         ["aws", "linux", "jenkins", "ci/cd", "docker", "kubernetes", "git", "bash"], "Remote", 1),
        ("QA Automation Tester", "QualityAssure Inc", 
         "Implement automated test suites using Selenium, Python or Java, Postman, and Pytest. Strong grasp of Unit Testing and Agile/Scrum methodologies.",
         ["selenium", "python", "testing", "postman", "pytest", "agile"], "Chennai, India", 0)
    ]

    for idx, (title, comp, desc, skills, loc, remote) in enumerate(sample_jobs, start=1):
        cur.execute(
            """
            INSERT INTO job_vacancies (source, external_id, company, title, description, skills_json, location, is_remote, apply_url, posted_at, is_active)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), 1)
            ON DUPLICATE KEY UPDATE title=VALUES(title), is_active=1
            """,
            ("seed", f"auto-seed-{idx}", comp, title, desc, json.dumps(skills), loc, remote, "https://remotive.com")
        )

def run():
    """Execute the full collector pipeline across all configured sources."""
    print(f"[{dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Starting Job Collector pipeline...")
    con = db()
    cur = con.cursor()

    # Always ensure baseline seed jobs exist
    try:
        seed_fallback_jobs(cur)
        con.commit()
    except Exception as e:
        print(f"Seed fallback error: {e}")

    sources = [
        ("remotive", collect_remotive),
        ("arbeitnow", collect_arbeitnow)
    ]
    if os.getenv("ADZUNA_APP_ID") and os.getenv("ADZUNA_APP_KEY"):
        sources.insert(0, ("adzuna", collect_adzuna))

    for name, collector_fn in sources:
        try:
            status = collector_fn(cur)
        except Exception as e:
            status = f"FAILED: {str(e)[:80]}"
        
        print(f"[{name.upper()}]: {status}")
        try:
            cur.execute(
                """
                INSERT INTO job_sources (name, last_status, last_run) 
                VALUES (%s, %s, NOW())
                ON DUPLICATE KEY UPDATE last_status=%s, last_run=NOW()
                """,
                (name, status, status)
            )
            con.commit()
        except Exception as e:
            print(f"Failed to record source status for {name}: {e}")

    # Mark jobs older than 60 days as inactive
    try:
        cur.execute("UPDATE job_vacancies SET is_active = 0 WHERE posted_at < NOW() - INTERVAL 60 DAY")
        con.commit()
    except Exception as e:
        print(f"Error archiving stale jobs: {e}")

    con.close()
    print("Job collection complete.")

if __name__ == "__main__":
    run()
    try:
        from apscheduler.schedulers.blocking import BlockingScheduler
        scheduler = BlockingScheduler()
        # Run every 6 hours
        scheduler.add_job(run, "interval", hours=6)
        print("Scheduled job collector to run every 6 hours. Press Ctrl+C to exit.")
        scheduler.start()
    except (ImportError, KeyboardInterrupt):
        print("Scheduler stopped or not available. Run completed.")
