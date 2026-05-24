import io
import fitz  # PyMuPDF
from PIL import Image

PNG_MAGIC = b"\x89PNG\r\n\x1a\n"

def _force_png(img_bytes: bytes) -> bytes:
    if img_bytes.startswith(PNG_MAGIC):
        return img_bytes
    im = Image.open(io.BytesIO(img_bytes)).convert("RGB")
    buf = io.BytesIO()
    im.save(buf, format="PNG")
    return buf.getvalue()

def pdf_to_images(pdf_bytes: bytes, dpi: int = 180) -> list[bytes]:
    images: list[bytes] = []
    doc = fitz.open(stream=pdf_bytes, filetype="pdf")
    try:
        if doc.page_count == 0:
            raise RuntimeError("PDF has zero pages")
        for page in doc:
            mat = fitz.Matrix(dpi/72, dpi/72)
            pix = page.get_pixmap(matrix=mat, alpha=False)
            b = pix.tobytes("png")
            if not b:
                raise RuntimeError("Page rasterization returned empty buffer")
            images.append(_force_png(b))
    finally:
        doc.close()
    return images

def ensure_png(image_or_bytes: bytes) -> bytes:
    return _force_png(image_or_bytes)
