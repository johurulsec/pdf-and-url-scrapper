from pydantic import BaseModel
from typing import Optional, List, Literal, Dict, Any

class Address(BaseModel):
    full: Optional[str] = None
    postalCode: Optional[str] = None
    prefecture: Optional[str] = None
    city: Optional[str] = None
    ward: Optional[str] = None
    district: Optional[str] = None
    chome: Optional[str] = None
    lat: Optional[float] = None      
    lng: Optional[float] = None      
    full_en: Optional[str] = None

class Areas(BaseModel):
    landArea_m2: Optional[float] = None
    buildingArea_m2: Optional[float] = None
    floorArea_m2: Optional[float] = None
    siteArea_m2: Optional[float] = None
    rawMentions: List[str] = []

class Road(BaseModel):
    width_m: Optional[float] = None
    frontage_m: Optional[float] = None
    direction: Optional[str] = None
    type: Optional[Literal["public","private"]] = None
    notes: Optional[str] = None

class Built(BaseModel):
    builtYearMonth: Optional[str] = None   
    renovation: Optional[str] = None

class Structure(BaseModel):
    code: Optional[str] = None            
    text: Optional[str] = None

class Parking(BaseModel):
    available: Optional[bool] = None
    count: Optional[int] = None
    type: Optional[str] = None
    text: Optional[str] = None

class Listing(BaseModel):
    propertyType: List[str] = []
    priceJPY: Optional[int] = None
    address: Address = Address()
    areas: Areas = Areas()
    rights: List[str] = []                
    share: Optional[str] = None
    landCategory: Optional[str] = None
    road: Road = Road()
    ratios: Dict[str, Optional[int | str]] = {
        "buildingCoverage_pct": None,
        "floorAreaRatio_pct": None,
        "floorAreaRatioEffective_pct": None,
        "notes": None
    }
    zoning: Optional[str] = None
    utilities: Dict[str, Optional[bool | str]] = {
        "water": None, "sewer": None, "gas": None, "cityGas": None, "electricity": None
    }
    status: Optional[str] = None
    transport: List[Dict[str, Any]] = []
    built: Built = Built()
    floorPlan: Optional[str] = None
    structure: Structure = Structure()
    parking: Parking = Parking()
    notes: Optional[str] = None

class Source(BaseModel):
    fileName: Optional[str] = None
    url: Optional[str] = None
    runAt: str
    mode: Literal["inline-file","url","batch","live-url"]
    model: str

class Tokens(BaseModel):
    inputEstimated: Optional[int] = None
    promptTokenCount: Optional[int] = None
    candidatesTokenCount: Optional[int] = None
    totalTokenCount: Optional[int] = None

class Meta(BaseModel):
    durationMs: Optional[int] = None
    tokens: Tokens = Tokens()

class ExtractEnvelope(BaseModel):
    schemaVersion: str
    requestId: str
    # Accept both Japanese and English
    locale: Literal['ja-JP', 'en-US'] = 'ja-JP'
    source: Source
    listing: Optional[Listing] = None
    raw: Dict[str, Any]
    meta: Dict[str, Any]
