#!/usr/bin/env python3
"""Generate property parcel maps with road-context basemap tiles."""

from __future__ import annotations

import io
import json
import math
import os
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Dict, List, Sequence, Tuple

from PIL import Image, ImageDraw, ImageFont

OUTPUT_DIR = "/Users/oshyan/Projects/Coding/property_maps"
TILE_CACHE_DIR = os.path.join(OUTPUT_DIR, ".tile_cache")
TILE_SIZE = 256
TILE_URL = "https://tile.openstreetmap.org/{z}/{x}/{y}.png"
USER_AGENT = "parcel-map-generator/1.0 (local analysis)"

FRESNO_QUERY_URL = (
    "https://services6.arcgis.com/Gs01XZPFhKUG8tKU/ArcGIS/rest/services/"
    "Fresno_County_Parcels/FeatureServer/0/query"
)
MADERA_QUERY_URL = (
    "https://services.arcgis.com/q3Zg9ERurv23iysr/arcgis/rest/services/"
    "Madera_County_Map/FeatureServer/0/query"
)


@dataclass
class ParcelRequest:
    apn_query: str
    apn_label: str
    county: str


@dataclass
class Group:
    key: str
    title: str
    file_stub: str
    color: Tuple[int, int, int]
    parcels: List[ParcelRequest]


@dataclass
class MapProjection:
    zoom: int
    crop_min_x: float
    crop_min_y: float
    map_left: int
    map_top: int
    map_width: int
    map_height: int
    center_lat: float

    def to_image_px(self, lon: float, lat: float) -> Tuple[float, float]:
        wx, wy = lonlat_to_world_px(lon, lat, self.zoom)
        return (
            self.map_left + (wx - self.crop_min_x),
            self.map_top + (wy - self.crop_min_y),
        )


def fetch_geojson(url: str, params: Dict[str, str]) -> dict:
    full_url = f"{url}?{urllib.parse.urlencode(params)}"
    req = urllib.request.Request(full_url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=30) as response:
        return json.load(response)


def get_query_for_county(county: str) -> str:
    if county == "Fresno":
        return FRESNO_QUERY_URL
    if county == "Madera":
        return MADERA_QUERY_URL
    raise ValueError(f"Unsupported county: {county}")


def query_parcel(parcel: ParcelRequest) -> dict:
    where = f"APN='{parcel.apn_query}'"
    params = {
        "where": where,
        "outFields": "APN",
        "returnGeometry": "true",
        "outSR": "4326",
        "f": "geojson",
    }
    data = fetch_geojson(get_query_for_county(parcel.county), params)
    features = data.get("features", [])
    if not features:
        raise RuntimeError(f"No feature found for {parcel.county} APN {parcel.apn_query}")
    feature = features[0]
    geom = feature.get("geometry", {})
    if geom.get("type") not in {"Polygon", "MultiPolygon"}:
        raise RuntimeError(f"Unsupported geometry for APN {parcel.apn_query}: {geom.get('type')}")
    return {
        "apn": parcel.apn_label,
        "county": parcel.county,
        "geometry": geom,
        "properties": feature.get("properties", {}),
    }


def polygon_outer_rings(geometry: dict) -> List[List[Tuple[float, float]]]:
    gtype = geometry.get("type")
    coords = geometry.get("coordinates", [])
    rings: List[List[Tuple[float, float]]] = []

    if gtype == "Polygon":
        polys = [coords]
    elif gtype == "MultiPolygon":
        polys = coords
    else:
        return rings

    for poly in polys:
        if not poly:
            continue
        outer = poly[0]
        ring = [(float(lon), float(lat)) for lon, lat in outer]
        if len(ring) >= 3:
            rings.append(ring)
    return rings


def hex_color(rgb: Tuple[int, int, int], alpha: int) -> Tuple[int, int, int, int]:
    return (rgb[0], rgb[1], rgb[2], alpha)


def collect_bounds(feature_items: Sequence[dict]) -> Tuple[float, float, float, float]:
    min_lon = math.inf
    max_lon = -math.inf
    min_lat = math.inf
    max_lat = -math.inf

    for item in feature_items:
        for ring in polygon_outer_rings(item["geometry"]):
            for lon, lat in ring:
                min_lon = min(min_lon, lon)
                max_lon = max(max_lon, lon)
                min_lat = min(min_lat, lat)
                max_lat = max(max_lat, lat)

    if not math.isfinite(min_lon):
        raise RuntimeError("No geometry bounds available")

    return min_lon, min_lat, max_lon, max_lat


def contextual_bounds(
    bounds: Tuple[float, float, float, float],
    padding_factor: float,
    min_span_lon: float = 0.0,
    min_span_lat: float = 0.0,
) -> Tuple[float, float, float, float]:
    min_lon, min_lat, max_lon, max_lat = bounds
    center_lon = (min_lon + max_lon) / 2.0
    center_lat = (min_lat + max_lat) / 2.0

    base_dx = max(max_lon - min_lon, min_span_lon, 1e-6)
    base_dy = max(max_lat - min_lat, min_span_lat, 1e-6)

    half_w = base_dx * (0.5 + padding_factor)
    half_h = base_dy * (0.5 + padding_factor)

    return (
        center_lon - half_w,
        center_lat - half_h,
        center_lon + half_w,
        center_lat + half_h,
    )


def clamp_lat(lat: float) -> float:
    return max(min(lat, 85.05112878), -85.05112878)


def lonlat_to_world_px(lon: float, lat: float, zoom: int) -> Tuple[float, float]:
    lat = clamp_lat(lat)
    n = 2 ** zoom
    x = (lon + 180.0) / 360.0 * n * TILE_SIZE
    lat_rad = math.radians(lat)
    y = (1.0 - math.log(math.tan(lat_rad) + (1.0 / math.cos(lat_rad))) / math.pi) / 2.0
    y *= n * TILE_SIZE
    return x, y


def choose_zoom(bounds: Tuple[float, float, float, float], map_width: int, map_height: int) -> int:
    min_lon, min_lat, max_lon, max_lat = bounds
    for zoom in range(18, 9, -1):
        x0, y0 = lonlat_to_world_px(min_lon, max_lat, zoom)
        x1, y1 = lonlat_to_world_px(max_lon, min_lat, zoom)
        span_w = abs(x1 - x0)
        span_h = abs(y1 - y0)
        if span_w <= map_width * 0.72 and span_h <= map_height * 0.72:
            return zoom
    return 10


def build_projection(
    bounds: Tuple[float, float, float, float],
    map_left: int,
    map_top: int,
    map_width: int,
    map_height: int,
) -> MapProjection:
    min_lon, min_lat, max_lon, max_lat = bounds
    zoom = choose_zoom(bounds, map_width, map_height)

    x0, y0 = lonlat_to_world_px(min_lon, max_lat, zoom)
    x1, y1 = lonlat_to_world_px(max_lon, min_lat, zoom)

    center_x = (x0 + x1) / 2.0
    center_y = (y0 + y1) / 2.0
    crop_min_x = center_x - map_width / 2.0
    crop_min_y = center_y - map_height / 2.0

    center_lat = (min_lat + max_lat) / 2.0
    return MapProjection(
        zoom=zoom,
        crop_min_x=crop_min_x,
        crop_min_y=crop_min_y,
        map_left=map_left,
        map_top=map_top,
        map_width=map_width,
        map_height=map_height,
        center_lat=center_lat,
    )


def load_tile(z: int, x: int, y: int) -> Image.Image:
    os.makedirs(TILE_CACHE_DIR, exist_ok=True)
    max_idx = (2 ** z) - 1

    if y < 0 or y > max_idx:
        return Image.new("RGB", (TILE_SIZE, TILE_SIZE), (240, 240, 240))

    x_wrapped = x % (2 ** z)
    cache_path = os.path.join(TILE_CACHE_DIR, f"{z}_{x_wrapped}_{y}.png")

    if os.path.exists(cache_path):
        with Image.open(cache_path) as img:
            return img.convert("RGB")

    url = TILE_URL.format(z=z, x=x_wrapped, y=y)
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            data = resp.read()
        with open(cache_path, "wb") as f:
            f.write(data)
        return Image.open(io.BytesIO(data)).convert("RGB")
    except Exception:
        tile = Image.new("RGB", (TILE_SIZE, TILE_SIZE), (240, 240, 240))
        d = ImageDraw.Draw(tile)
        d.line([(0, 0), (TILE_SIZE, TILE_SIZE)], fill=(210, 210, 210), width=2)
        d.line([(TILE_SIZE, 0), (0, TILE_SIZE)], fill=(210, 210, 210), width=2)
        return tile


def render_basemap(proj: MapProjection) -> Image.Image:
    basemap = Image.new("RGB", (proj.map_width, proj.map_height), (240, 240, 240))

    world_x0 = proj.crop_min_x
    world_y0 = proj.crop_min_y
    world_x1 = proj.crop_min_x + proj.map_width
    world_y1 = proj.crop_min_y + proj.map_height

    tile_x_min = math.floor(world_x0 / TILE_SIZE)
    tile_x_max = math.floor(world_x1 / TILE_SIZE)
    tile_y_min = math.floor(world_y0 / TILE_SIZE)
    tile_y_max = math.floor(world_y1 / TILE_SIZE)

    for tx in range(tile_x_min, tile_x_max + 1):
        for ty in range(tile_y_min, tile_y_max + 1):
            tile = load_tile(proj.zoom, tx, ty)
            px = int(round(tx * TILE_SIZE - world_x0))
            py = int(round(ty * TILE_SIZE - world_y0))
            basemap.paste(tile, (px, py))

    return basemap


def ring_centroid(ring: Sequence[Tuple[float, float]]) -> Tuple[float, float]:
    a = 0.0
    cx = 0.0
    cy = 0.0
    n = len(ring)
    if n < 3:
        return ring[0]
    for i in range(n - 1):
        x0, y0 = ring[i]
        x1, y1 = ring[i + 1]
        cross = x0 * y1 - x1 * y0
        a += cross
        cx += (x0 + x1) * cross
        cy += (y0 + y1) * cross
    if abs(a) < 1e-12:
        xs = [p[0] for p in ring]
        ys = [p[1] for p in ring]
        return (sum(xs) / len(xs), sum(ys) / len(ys))
    a *= 0.5
    return (cx / (6.0 * a), cy / (6.0 * a))


def load_fonts() -> Tuple[ImageFont.ImageFont, ImageFont.ImageFont, ImageFont.ImageFont]:
    candidates = [
        "/System/Library/Fonts/Supplemental/Arial.ttf",
        "/Library/Fonts/Arial.ttf",
        "/System/Library/Fonts/Supplemental/Helvetica.ttc",
    ]
    for path in candidates:
        if os.path.exists(path):
            title = ImageFont.truetype(path, 42)
            body = ImageFont.truetype(path, 24)
            small = ImageFont.truetype(path, 18)
            return title, body, small
    fallback = ImageFont.load_default()
    return fallback, fallback, fallback


def format_scale_distance(meters: float) -> str:
    if meters >= 1000:
        km = meters / 1000.0
        return f"{km:.1f} km" if km < 10 else f"{km:.0f} km"
    return f"{int(round(meters))} m"


def draw_scale_bar(draw: ImageDraw.ImageDraw, proj: MapProjection, small_font: ImageFont.ImageFont) -> None:
    meters_per_px = (156543.03392 * math.cos(math.radians(proj.center_lat))) / (2 ** proj.zoom)
    target_px = 160
    options_m = [50, 100, 200, 250, 500, 1000, 2000, 5000, 10000]

    chosen = options_m[0]
    for m in options_m:
        if (m / meters_per_px) <= target_px:
            chosen = m

    bar_px = int(round(chosen / meters_per_px))
    x0 = proj.map_left + 30
    y0 = proj.map_top + proj.map_height - 28

    draw.rounded_rectangle(
        [(x0 - 10, y0 - 26), (x0 + bar_px + 10, y0 + 12)],
        radius=8,
        fill=(255, 255, 255, 220),
        outline=(90, 90, 90, 200),
        width=1,
    )
    draw.line([(x0, y0), (x0 + bar_px, y0)], fill=(25, 25, 25, 255), width=4)
    draw.line([(x0, y0 - 6), (x0, y0 + 6)], fill=(25, 25, 25, 255), width=2)
    draw.line([(x0 + bar_px, y0 - 6), (x0 + bar_px, y0 + 6)], fill=(25, 25, 25, 255), width=2)
    draw.text((x0 + 6, y0 - 24), format_scale_distance(chosen), font=small_font, fill=(30, 30, 30, 255))


def draw_north_arrow(draw: ImageDraw.ImageDraw, proj: MapProjection, small_font: ImageFont.ImageFont) -> None:
    x = proj.map_left + proj.map_width - 36
    y = proj.map_top + 38
    draw.polygon([(x, y - 18), (x - 8, y + 8), (x + 8, y + 8)], fill=(20, 20, 20, 220))
    draw.text((x - 5, y + 10), "N", font=small_font, fill=(20, 20, 20, 255))


def draw_map(
    path: str,
    title: str,
    groups: Sequence[Group],
    group_features: Dict[str, List[dict]],
    bounds: Tuple[float, float, float, float],
    show_legend: bool,
) -> None:
    width, height = 2200, 1500
    map_left, map_right = 28, 28
    map_top = 92
    map_bottom = 58

    map_width = width - map_left - map_right
    map_height = height - map_top - map_bottom

    title_font, body_font, small_font = load_fonts()

    proj = build_projection(bounds, map_left, map_top, map_width, map_height)
    basemap = render_basemap(proj)

    image = Image.new("RGBA", (width, height), (245, 245, 245, 255))
    image.paste(basemap, (proj.map_left, proj.map_top))
    draw = ImageDraw.Draw(image, "RGBA")

    draw.rectangle([(proj.map_left, proj.map_top), (proj.map_left + proj.map_width, proj.map_top + proj.map_height)], outline=(80, 80, 80, 150), width=2)

    for group in groups:
        feats = group_features[group.key]
        fill = hex_color(group.color, 128)

        for feat in feats:
            for ring in polygon_outer_rings(feat["geometry"]):
                xy = [proj.to_image_px(lon, lat) for lon, lat in ring]
                draw.polygon(xy, fill=fill)
                draw.line(xy, fill=(255, 255, 255, 240), width=9)
                draw.line(xy, fill=hex_color(group.color, 255), width=6)
                draw.line(xy, fill=(20, 20, 20, 240), width=2)

            rings = polygon_outer_rings(feat["geometry"])
            if rings:
                c_lon, c_lat = ring_centroid(rings[0])
                cx, cy = proj.to_image_px(c_lon, c_lat)
                draw.ellipse([(cx - 7, cy - 7), (cx + 7, cy + 7)], fill=hex_color(group.color, 255), outline=(255, 255, 255, 255), width=2)

                label = feat["apn"]
                bbox = draw.textbbox((0, 0), label, font=body_font)
                tw = bbox[2] - bbox[0]
                th = bbox[3] - bbox[1]
                draw.rounded_rectangle(
                    [(cx - tw / 2 - 9, cy - th / 2 - 7), (cx + tw / 2 + 9, cy + th / 2 + 7)],
                    radius=7,
                    fill=(255, 255, 255, 225),
                    outline=(85, 85, 85, 240),
                    width=1,
                )
                draw.text((cx - tw / 2, cy - th / 2 - 1), label, font=body_font, fill=(22, 22, 22, 255))

    draw.rectangle([(0, 0), (width, 84)], fill=(255, 255, 255, 236))
    draw.text((24, 18), title, font=title_font, fill=(18, 18, 18, 255))

    if show_legend:
        legend_x = width - 760
        legend_y = 108
        legend_w = 730
        legend_h = 44 + 46 * len(groups)
        draw.rounded_rectangle(
            [(legend_x, legend_y), (legend_x + legend_w, legend_y + legend_h)],
            radius=12,
            fill=(255, 255, 255, 228),
            outline=(105, 105, 105, 220),
            width=1,
        )
        draw.text((legend_x + 14, legend_y + 9), "Legend", font=body_font, fill=(22, 22, 22, 255))
        y = legend_y + 52
        for g in groups:
            draw.rectangle([(legend_x + 18, y + 10), (legend_x + 42, y + 34)], fill=hex_color(g.color, 255), outline=(30, 30, 30, 255), width=1)
            draw.text((legend_x + 54, y + 8), g.title, font=small_font, fill=(32, 32, 32, 255))
            y += 46

    draw_scale_bar(draw, proj, small_font)
    draw_north_arrow(draw, proj, small_font)

    footer = "Parcels: Fresno County Parcels + Madera County Map (ArcGIS). Basemap roads: OpenStreetMap contributors."
    draw.rectangle([(0, height - 40), (width, height)], fill=(255, 255, 255, 236))
    draw.text((14, height - 31), footer, font=small_font, fill=(70, 70, 70, 255))

    image.convert("RGB").save(path, format="PNG", optimize=True)


def main() -> None:
    os.makedirs(OUTPUT_DIR, exist_ok=True)

    groups = [
        Group(
            key="barstow",
            title="W. Barstow (APN 505-060-08)",
            file_stub="01_barstow",
            color=(211, 47, 47),
            parcels=[ParcelRequest("50506008", "505-060-08", "Fresno")],
        ),
        Group(
            key="cleveland",
            title="Cleveland Ave Pads (013-141-034 / 013-141-035)",
            file_stub="02_cleveland",
            color=(30, 136, 229),
            parcels=[
                ParcelRequest("013-141-034", "013-141-034 (Pad A)", "Madera"),
                ParcelRequest("013-141-035", "013-141-035 (Pad B)", "Madera"),
            ],
        ),
        Group(
            key="cornelia",
            title="N. Cornelia (511-220-32S / 511-220-33S)",
            file_stub="03_cornelia",
            color=(46, 125, 50),
            parcels=[
                ParcelRequest("51122032S", "511-220-32S", "Fresno"),
                ParcelRequest("51122033S", "511-220-33S", "Fresno"),
            ],
        ),
        Group(
            key="blythe",
            title="N. Blythe (APN 511-031-42S)",
            file_stub="04_blythe",
            color=(251, 140, 0),
            parcels=[ParcelRequest("51103142S", "511-031-42S", "Fresno")],
        ),
    ]

    group_features: Dict[str, List[dict]] = {}
    for g in groups:
        features: List[dict] = []
        for p in g.parcels:
            features.append(query_parcel(p))
        group_features[g.key] = features

    all_features = [feature for features in group_features.values() for feature in features]

    combined_bounds = contextual_bounds(
        collect_bounds(all_features),
        padding_factor=0.28,
    )
    draw_map(
        path=os.path.join(OUTPUT_DIR, "00_all_properties_map.png"),
        title="Combined Property Map (Road Context)",
        groups=groups,
        group_features=group_features,
        bounds=combined_bounds,
        show_legend=True,
    )

    for group in groups:
        raw_bounds = collect_bounds(group_features[group.key])
        min_lon, min_lat, max_lon, max_lat = raw_bounds
        span_lon = max_lon - min_lon
        span_lat = max_lat - min_lat
        group_bounds = contextual_bounds(
            raw_bounds,
            padding_factor=0.38,
            min_span_lon=max(span_lon * 2.2, 0.0045),
            min_span_lat=max(span_lat * 2.2, 0.0035),
        )
        draw_map(
            path=os.path.join(OUTPUT_DIR, f"{group.file_stub}_outline.png"),
            title=f"Parcel Outline + Road Context: {group.title}",
            groups=[group],
            group_features={group.key: group_features[group.key]},
            bounds=group_bounds,
            show_legend=False,
        )

    summary = {
        "generated_files": [
            "00_all_properties_map.png",
            "01_barstow_outline.png",
            "02_cleveland_outline.png",
            "03_cornelia_outline.png",
            "04_blythe_outline.png",
        ],
        "counts": {key: len(vals) for key, vals in group_features.items()},
    }
    with open(os.path.join(OUTPUT_DIR, "summary.json"), "w", encoding="utf-8") as f:
        json.dump(summary, f, indent=2)


if __name__ == "__main__":
    main()
