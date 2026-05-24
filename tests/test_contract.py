from app.schemas_listing import ExtractEnvelope

def test_envelope_contract():
    sample = {
      "schemaVersion": "1.0",
      "requestId": "abcd1234",
      "locale": "ja-JP",
      "source": {"fileName": "x.pdf", "runAt": "2025-01-01T00:00:00Z", "mode": "inline-file", "model": "gemini-2.5-flash"},
      "listing": {},
      "raw": {"groups": {}},
      "meta": {"durationMs": 10, "tokens": {}}
    }
    ExtractEnvelope.model_validate(sample)
