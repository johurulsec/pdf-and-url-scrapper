import fitz  # PyMuPDF

def pdf_to_text_pages(pdf_bytes: bytes) -> list[str]:
    """
    Extracts selectable (vector) text from each PDF page.
    Returns a list of page strings (may be empty for image-only PDFs).
    """
    texts: list[str] = []
    doc = fitz.open(stream=pdf_bytes, filetype="pdf")
    try:
        for page in doc:
            t = page.get_text("text") or ""
            texts.append(t)
    finally:
        doc.close()
    return texts
