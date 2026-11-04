import os
import re
import json
import mysql.connector
from dotenv import load_dotenv

# Load .env from root directory or current directory
env_path = os.path.join(os.path.dirname(__file__), "..", ".env")
if os.path.exists(env_path):
    load_dotenv(env_path)
else:
    load_dotenv()

def db():
    """Establish and return a MySQL connection."""
    port_val = os.getenv("DB_PORT", "3306")
    try:
        port = int(port_val)
    except ValueError:
        port = 3306

    return mysql.connector.connect(
        host=os.getenv("DB_HOST", "localhost"),
        port=port,
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASS", ""),
        database=os.getenv("DB_NAME", "resume_ai"),
        charset="utf8mb4"
    )

# Expanded library of 150+ in-demand industry skills
SKILLS = [
    # Programming Languages
    "python", "java", "javascript", "typescript", "c++", "c#", "c", "php", "ruby", "go", "golang",
    "rust", "swift", "kotlin", "scala", "dart", "r", "perl", "bash", "shell",

    # Web & Frontend Frameworks
    "html", "html5", "css", "css3", "react", "react.js", "angular", "vue", "vue.js", "next.js", "nuxt.js",
    "svelte", "bootstrap", "tailwind css", "sass", "less", "jquery", "redux", "webpack", "vite",

    # Backend & Frameworks
    "node.js", "nodejs", "express", "express.js", "django", "flask", "fastapi", "spring", "spring boot",
    "laravel", "asp.net", ".net", "ruby on rails", "graphql", "rest api", "microservices", "websockets",

    # Databases & Caching
    "sql", "mysql", "postgresql", "postgres", "sqlite", "mongodb", "redis", "cassandra", "dynamodb",
    "oracle", "mariadb", "elasticsearch", "neo4j", "firebase", "supabase",

    # Data Science, AI & Machine Learning
    "machine learning", "deep learning", "nlp", "natural language processing", "computer vision",
    "tensorflow", "pytorch", "keras", "scikit-learn", "pandas", "numpy", "scipy", "matplotlib",
    "seaborn", "opencv", "data analysis", "data engineering", "power bi", "tableau", "excel",
    "spark", "hadoop", "airflow", "snowflake", "bigquery", "llm", "langchain", "hugging face",

    # Cloud, DevOps & Infrastructure
    "aws", "azure", "gcp", "google cloud", "docker", "kubernetes", "k8s", "git", "github", "gitlab",
    "linux", "unix", "devops", "ci/cd", "jenkins", "terraform", "ansible", "nginx", "apache",
    "prometheus", "grafana",

    # QA, Testing & Security
    "selenium", "testing", "unit testing", "jest", "cypress", "junit", "pytest", "postman",
    "cybersecurity", "information security", "penetration testing", "ethical hacking", "networking",
    "tcp/ip", "cryptography",

    # Mobile & Other
    "android", "ios", "react native", "flutter", "agile", "scrum", "jira", "figma", "ui/ux"
]

# Normalization mapping for synonymous skills
SKILL_ALIASES = {
    "react.js": "react",
    "nodejs": "node.js",
    "vue.js": "vue",
    "golang": "go",
    "postgres": "postgresql",
    "k8s": "kubernetes",
    "google cloud": "gcp",
    "html5": "html",
    "css3": "css",
    "express.js": "express",
    "natural language processing": "nlp"
}

# Curated free learning courses and resources
def get_curated_courses(skill: str):
    base_encoded = skill.replace(" ", "%20")
    skill_title = skill.title()
    
    # Specific recognized high-value course links
    specialized_links = {
        "python": [
            {"name": "Python for Everybody (Coursera / Free Audit)", "url": "https://www.coursera.org/specializations/python", "type": "free audit"},
            {"name": "Scientific Computing with Python (freeCodeCamp)", "url": "https://www.freecodecamp.org/learn/scientific-computing-with-python/", "type": "free certificate"}
        ],
        "javascript": [
            {"name": "JavaScript Algorithms & Data Structures (freeCodeCamp)", "url": "https://www.freecodecamp.org/learn/javascript-algorithms-and-data-structures-v8/", "type": "free certificate"},
            {"name": "The Modern JavaScript Tutorial", "url": "https://javascript.info/", "type": "free guide"}
        ],
        "react": [
            {"name": "Official React Documentation & Interactive Learn", "url": "https://react.dev/learn", "type": "free documentation"},
            {"name": "Front End Development Libraries (freeCodeCamp)", "url": "https://www.freecodecamp.org/learn/front-end-development-libraries/", "type": "free certificate"}
        ],
        "sql": [
            {"name": "Relational Databases & SQL (freeCodeCamp / SQLZoo)", "url": "https://sqlzoo.net/", "type": "free interactive"},
            {"name": "SQL for Data Science (Coursera)", "url": "https://www.coursera.org/learn/sql-for-data-science", "type": "free audit"}
        ],
        "machine learning": [
            {"name": "Machine Learning Specialization by Andrew Ng (Coursera)", "url": "https://www.coursera.org/specializations/machine-learning-introduction", "type": "free audit"},
            {"name": "Machine Learning with Python (freeCodeCamp)", "url": "https://www.freecodecamp.org/learn/machine-learning-with-python/", "type": "free certificate"}
        ],
        "docker": [
            {"name": "Docker 101 Tutorial & Official Lab", "url": "https://www.docker.com/101-tutorial/", "type": "free interactive"},
            {"name": "Docker for Beginners (freeCodeCamp)", "url": "https://www.freecodecamp.org/news/the-docker-handbook/", "type": "free handbook"}
        ],
        "git": [
            {"name": "Pro Git Book (Official Free Reference)", "url": "https://git-scm.com/book/en/v2", "type": "free book"},
            {"name": "Git & GitHub Crash Course (freeCodeCamp)", "url": "https://www.youtube.com/watch?v=RGOj5yH7evk", "type": "free video"}
        ],
        "aws": [
            {"name": "AWS Skill Builder Free Digital Training", "url": "https://explore.skillbuilder.aws/", "type": "free official training"},
            {"name": "AWS Certified Cloud Practitioner Prep (freeCodeCamp)", "url": "https://www.youtube.com/watch?v=SOTamWNgDKc", "type": "free video"}
        ]
    }

    if skill in specialized_links:
        return specialized_links[skill]

    return [
        {
            "name": f"{skill_title} Course (Coursera Free Audit)",
            "url": f"https://www.coursera.org/search?query={base_encoded}",
            "type": "free audit / certificate option"
        },
        {
            "name": f"{skill_title} Certification & Tutorials (freeCodeCamp)",
            "url": f"https://www.freecodecamp.org/news/search/?query={base_encoded}",
            "type": "free tutorials"
        },
        {
            "name": "NPTEL / SWAYAM Technical Courses",
            "url": "https://nptel.ac.in/courses",
            "type": "free government education"
        }
    ]

COURSES = {s: get_curated_courses(s) for s in SKILLS}

def find_skills(text: str) -> list[str]:
    """
    Extract skills from text with boundary-aware regex matching
    and synonym normalization.
    """
    if not text:
        return []

    t = text.lower()
    matched = set()

    for skill in SKILLS:
        # For single or two letter skills like 'c' or 'r', require strict boundaries
        if len(skill) <= 2 and skill not in ["c++", "c#", "r", "go", "ci"]:
            pattern = rf"\b{re.escape(skill)}\b"
        elif skill in ["c++", "c#"]:
            pattern = rf"(?<![a-z0-9]){re.escape(skill)}(?![a-z0-9])"
        elif skill in ["c", "r", "go"]:
            # Avoid matching random letters inside words
            pattern = rf"(?<![a-z0-9_]){re.escape(skill)}(?![a-z0-9_])"
        else:
            pattern = rf"(?<![a-z0-9+#]){re.escape(skill)}(?![a-z0-9+#])"

        if re.search(pattern, t):
            canonical = SKILL_ALIASES.get(skill, skill)
            matched.add(canonical)

    return sorted(matched)
