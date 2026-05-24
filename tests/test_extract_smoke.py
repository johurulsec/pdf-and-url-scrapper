from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)

def test_extract_empty():
    r = client.post("/extract", files={"file": ("empty.pdf", b"")})
    assert r.status_code == 400
