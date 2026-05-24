import os
from pydantic import BaseModel
from dotenv import load_dotenv

load_dotenv()

class Settings(BaseModel):
    ENV: str = os.getenv("ENV", "dev")
    LOCALE: str = os.getenv("LOCALE", "ja-JP")
    SCHEMA_VERSION: str = os.getenv("SCHEMA_VERSION", "1.0")

    GOOGLE_API_KEY: str | None = os.getenv("GOOGLE_API_KEY")
    GEMINI_MODEL: str = os.getenv("GEMINI_MODEL", "gemini-2.5-flash")

    USE_VERTEX: bool = os.getenv("GOOGLE_GENAI_USE_VERTEXAI", "false").lower() in ("1","true","yes")
    GCP_PROJECT: str | None = os.getenv("GOOGLE_CLOUD_PROJECT")
    GCP_LOCATION: str = os.getenv("GOOGLE_CLOUD_LOCATION", "us-central1")

    STRICT_VERBATIM: bool = os.getenv("STRICT_VERBATIM", "false").lower() in ("1","true","yes")
    MAX_PAGES: int = int(os.getenv("MAX_PAGES", "4"))
    INCLUDE_OCR_HINT: bool = os.getenv("INCLUDE_OCR_HINT", "true").lower() in ("1","true","yes")

settings = Settings()
