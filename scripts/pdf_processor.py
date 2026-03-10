#!/usr/bin/env python3
"""
PDF Processor for KT Minatoku PDF Editor
Uses PyMuPDF (fitz) for PDF processing
"""

import os
import sys
import json
import base64
import fitz  # PyMuPDF
from pathlib import Path
from typing import Dict, List, Any
import shutil
import subprocess


class PdfProcessor:
    """Handle PDF processing operations using PyMuPDF"""

    def __init__(self, pdf_path: str):
        """
        Initialize PDF processor

        Args:
            pdf_path: Path to the PDF file
        """
        self.pdf_path = Path(pdf_path)
        self.doc = None

        if not self.pdf_path.exists():
            raise FileNotFoundError(f"PDF file not found: {pdf_path}")

        try:
            self.doc = fitz.open(str(self.pdf_path))
        except Exception as e:
            raise ValueError(f"Failed to open PDF: {str(e)}")

    def get_metadata(self) -> Dict[str, Any]:
        """
        Extract PDF metadata

        Returns:
            Dictionary containing PDF metadata
        """
        metadata = {
            'page_count': len(self.doc),
            'title': self.doc.metadata.get('title', ''),
            'author': self.doc.metadata.get('author', ''),
            'subject': self.doc.metadata.get('subject', ''),
            'creator': self.doc.metadata.get('creator', ''),
            'producer': self.doc.metadata.get('producer', ''),
            'format': self.doc.metadata.get('format', 'PDF'),
            'encryption': self.doc.is_encrypted,
        }

        return metadata

    def generate_thumbnails(self, output_dir: str, max_width: int = 200) -> List[Dict[str, Any]]:
        """
        Generate thumbnails for all pages

        Args:
            output_dir: Directory to save thumbnails
            max_width: Maximum width for thumbnails

        Returns:
            List of thumbnail information
        """
        output_path = Path(output_dir)
        output_path.mkdir(parents=True, exist_ok=True)

        thumbnails = []

        for page_num in range(len(self.doc)):
            try:
                pix = self._safe_render_page_thumbnail(page_num, max_width)

                if pix is None:
                    # Skip this page but continue others
                    continue

                thumbnail_filename = f"page_{page_num + 1}.png"
                thumbnail_path = output_path / thumbnail_filename

                pix.save(str(thumbnail_path))

                thumbnails.append({
                    'page': page_num + 1,
                    'filename': thumbnail_filename,
                    'width': pix.width,
                    'height': pix.height,
                })
            except Exception:
                # Skip problematic page instead of crashing the whole process
                continue

        return thumbnails

    def _safe_render_page_thumbnail(self, page_num: int, max_width: int) -> Any:
        """Render a page to pixmap using multiple guarded strategies to avoid MuPDF crashes.

        Returns None if all strategies fail.
        """
        strategies = [
            {'alpha': True,  'annots': False, 'colorspace': None, 'normalize': False},
            {'alpha': False, 'annots': False, 'colorspace': None, 'normalize': False},
            {'alpha': True,  'annots': False, 'colorspace': fitz.csRGB, 'normalize': False},
            # Try normalizing the PDF to a new stream (helps with damaged structures)
            {'alpha': True,  'annots': False, 'colorspace': None, 'normalize': True},
        ]

        for strat in strategies:
            try:
                # Optionally normalize the PDF by converting to a fresh PDF stream
                if strat['normalize']:
                    with fitz.open(stream=self.doc.convert_to_pdf(), filetype='pdf') as norm_doc:
                        page = norm_doc.load_page(page_num)
                        return self._render_pix(page, max_width, strat['alpha'], strat['annots'], strat['colorspace'])
                else:
                    with fitz.open(str(self.pdf_path)) as tmp_doc:
                        page = tmp_doc.load_page(page_num)
                        return self._render_pix(page, max_width, strat['alpha'], strat['annots'], strat['colorspace'])
            except Exception:
                continue

        return None

    def _render_pix(self, page: fitz.Page, max_width: int, alpha: bool, annots: bool, colorspace) -> Any:
        # Guard for zero / extreme dimensions
        page_width = float(page.rect.width) if page.rect.width else 1.0
        # Cap target width to 4096 px to avoid huge rasters that may crash
        target_width = min(max_width, 4096)
        scale = target_width / page_width
        mat = fitz.Matrix(scale, scale)

        # Render with requested flags
        return page.get_pixmap(matrix=mat, alpha=alpha, annots=annots, colorspace=colorspace)

    # -------- CLI fallbacks (Poppler: pdftoppm) --------
    def _pdftoppm_available(self) -> bool:
        return shutil.which('pdftoppm') is not None

    def _pdftoppm_thumbnails(self, output_dir: str, max_width: int) -> List[Dict[str, Any]]:
        if not self._pdftoppm_available():
            return []
        out_prefix = str(Path(output_dir) / 'page')
        cmd = [
            'pdftoppm',
            '-png',
            '-scale-to-x', str(max_width),
            '-scale-to-y', '-1',
            str(self.pdf_path),
            out_prefix
        ]
        try:
            subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        except Exception:
            return []

        thumbs: List[Dict[str, Any]] = []
        # Files named like page-1.png, page-2.png ...
        for p in sorted(Path(output_dir).glob('page-*.png')):
            try:
                # Quick probe size using PIL if available; else leave zeros
                width = 0
                height = 0
                try:
                    from PIL import Image  # type: ignore
                    with Image.open(p) as im:
                        width, height = im.size
                except Exception:
                    pass
                page_no = int(p.stem.split('-')[-1])
                thumbs.append({
                    'page': page_no,
                    'filename': p.name,
                    'width': width,
                    'height': height,
                })
            except Exception:
                continue
        return thumbs

    def _pdftoppm_render_page(self, page_num: int, max_width: int) -> Dict[str, Any]:
        if not self._pdftoppm_available():
            raise ValueError('pdftoppm not available')
        # Render single page to stdout as PNG
        cmd = [
            'pdftoppm',
            '-png',
            '-f', str(page_num + 1),
            '-l', str(page_num + 1),
            '-scale-to-x', str(max_width),
            '-scale-to-y', '-1',
            str(self.pdf_path),
            '-'
        ]
        proc = subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        img_bytes = proc.stdout
        if not img_bytes:
            raise ValueError('pdftoppm returned empty image')
        b64 = base64.b64encode(img_bytes).decode('utf-8')
        # Dimensions unknown without PIL; return zeros if PIL missing
        width = 0
        height = 0
        try:
            from PIL import Image  # type: ignore
            import io
            with Image.open(io.BytesIO(img_bytes)) as im:
                width, height = im.size
        except Exception:
            pass
        return {
            'page': page_num + 1,
            'width': width,
            'height': height,
            'image_data': b64,
        }

    def render_page(self, page_num: int, zoom: float = 1.0, dpi: int = 150) -> Dict[str, Any]:
        """
        Render a specific page

        Args:
            page_num: Page number (0-indexed)
            zoom: Zoom level (1.0 = 100%)
            dpi: DPI for rendering

        Returns:
            Dictionary with page image data
        """
        if page_num < 0 or page_num >= len(self.doc):
            raise ValueError(f"Invalid page number: {page_num}")

        page = self.doc[page_num]

        # Calculate matrix for zoom and DPI
        zoom_matrix = fitz.Matrix(zoom, zoom)
        mat = zoom_matrix * fitz.Matrix(dpi / 72, dpi / 72)

        pix = page.get_pixmap(matrix=mat, alpha=False)

        # Convert to base64
        img_data = base64.b64encode(pix.tobytes("png")).decode('utf-8')

        return {
            'page': page_num + 1,
            'width': pix.width,
            'height': pix.height,
            'image_data': img_data,
            'original_width': page.rect.width,
            'original_height': page.rect.height,
        }

    def extract_images(self, output_dir: str) -> List[Dict[str, Any]]:
        """
        Extract original images from PDF, cropped to match visible bounds.

        Extracts raw image data but crops white space to match the visible
        cropped size as it appears in the PDF.

        Args:
            output_dir: Directory to save extracted images

        Returns:
            List of extracted image metadata
        """
        output_path = Path(output_dir)
        output_path.mkdir(parents=True, exist_ok=True)

        extracted: List[Dict[str, Any]] = []

        for page_index, page in enumerate(self.doc):
            page_no = page_index + 1

            try:
                images = page.get_images(full=True)
            except Exception:
                continue

            for img_idx, img in enumerate(images):
                xref = img[0]

                try:
                    # Extract the raw image data from PDF
                    img_data = self.doc.extract_image(xref)
                    if not img_data:
                        continue

                    # Get image bytes and info
                    image_bytes = img_data["image"]
                    if not image_bytes:
                        continue

                    width = img_data.get("width", 0)
                    height = img_data.get("height", 0)
                    orig_width, orig_height = width, height

                    # Try to get display rect and transformation matrix (for PDF-author crop)
                    rects = page.get_image_rects(xref)
                    try:
                        rects_with_transform = page.get_image_rects(xref, transform=True)
                    except Exception:
                        rects_with_transform = []
                    pdf_display_crop = None  # (x0, y0, x1, y1) in pixel coords, or None

                    if width and height and rects_with_transform:
                        try:
                            # get_image_rects(transform=True) returns list of (rect, matrix)
                            disp_rect, matrix = rects_with_transform[0]
                            inv = ~matrix
                            if bool(inv):
                                # Transform display rect corners from page to image space (PDF uses unit square 0..1)
                                corners = [
                                    fitz.Point(disp_rect.x0, disp_rect.y0),
                                    fitz.Point(disp_rect.x1, disp_rect.y0),
                                    fitz.Point(disp_rect.x1, disp_rect.y1),
                                    fitz.Point(disp_rect.x0, disp_rect.y1),
                                ]
                                image_corners = [p / matrix for p in corners]
                                xs = [q.x for q in image_corners]
                                ys = [q.y for q in image_corners]
                                x0, x1 = min(xs), max(xs)
                                y0, y1 = min(ys), max(ys)
                                raw_bounds = (x0, y0, x1, y1)  # for debug before clamp
                                unit_square = bool(x1 <= 1.5 and y1 <= 1.5 and x0 >= -0.5 and y0 >= -0.5)
                                # PDF image space: x right, y up (origin bottom-left). Pixel space: x right, y down (origin top-left).
                                # So we must flip y: pixel_y corresponds to (1 - pdf_y) for unit square, or (height - pdf_y) for pixel units.
                                if unit_square:
                                    # Unit square: clamp to [0,1] and scale to pixels; flip y for top-left origin
                                    x0 = max(0.0, min(1.0, x0))
                                    x1 = max(0.0, min(1.0, x1))
                                    y0 = max(0.0, min(1.0, y0))
                                    y1 = max(0.0, min(1.0, y1))
                                    px0 = int(x0 * width)
                                    px1 = int(x1 * width)
                                    # PDF y=0 is bottom, y=1 is top -> pixel top = (1-y1)*h, bottom = (1-y0)*h
                                    py0 = int((1.0 - y1) * height)
                                    py1 = int((1.0 - y0) * height)
                                else:
                                    # Assume image space in pixel units (y still PDF-style: 0 at bottom)
                                    px0 = int(max(0, min(width, x0)))
                                    px1 = int(max(0, min(width, x1)))
                                    py0 = int(max(0, min(height, height - y1)))
                                    py1 = int(max(0, min(height, height - y0)))
                                if px1 > px0 and py1 > py0:
                                    px1 = max(px1, px0 + 1)
                                    py1 = max(py1, py0 + 1)
                                    if px1 <= width and py1 <= height:
                                        # Only treat as PDF crop when the region is actually smaller than the full image
                                        crop_w = px1 - px0
                                        crop_h = py1 - py0
                                        if crop_w < width - 2 or crop_h < height - 2:
                                            pdf_display_crop = (px0, py0, px1, py1)
                        except Exception as e:
                            pdf_display_crop = None

                    # Process the image (optionally apply PDF-display crop, then white-space crop)
                    try:
                        from PIL import Image
                        import io

                        # Load the original image
                        pil_img = Image.open(io.BytesIO(image_bytes))

                        # If PDF author cropped the image (only part visible), crop to that region first
                        if pdf_display_crop is not None:
                            pil_img = pil_img.crop(pdf_display_crop)
                            width, height = pil_img.size

                        # Detect content bounds (remove white/background)
                        if pil_img.mode == 'RGBA':
                            bbox = pil_img.split()[-1].getbbox()
                        else:
                            # For RGB, use content detection
                            gray = pil_img.convert('L')
                            mask = gray.point(lambda p: 255 if p < 250 else 0)
                            bbox = mask.getbbox()

                        # Crop if we found content bounds (white-space removal)
                        if bbox:
                            pil_img = pil_img.crop(bbox)
                        buffer = io.BytesIO()
                        pil_img.save(buffer, format='PNG')
                        final_bytes = buffer.getvalue()
                        final_width, final_height = pil_img.size

                    except ImportError:
                        # PIL not available, use original
                        final_bytes = image_bytes
                        final_width, final_height = orig_width, orig_height
                    except Exception:
                        # Error processing, use original
                        final_bytes = image_bytes
                        final_width, final_height = orig_width, orig_height

                    # Generate filename
                    filename = f"img_p{page_no}_{xref}.png"
                    filepath = output_path / filename

                    # Save processed image
                    with open(filepath, "wb") as f:
                        f.write(final_bytes)

                    # Use display rect for position/size. When we used matrix crop, use the rect
                    # from (rect, matrix) so the frontend gets the cropped region's size on the page.
                    if rects_with_transform and len(rects_with_transform) > 0:
                        disp_rect = rects_with_transform[0][0]
                        x, y = disp_rect.x0, disp_rect.y0
                        rect_width, rect_height = disp_rect.width, disp_rect.height
                    elif rects:
                        rect = rects[0]
                        x, y = rect.x0, rect.y0
                        rect_width, rect_height = rect.width, rect.height
                    else:
                        x, y = 0, 0
                        rect_width, rect_height = final_width, final_height

                    extracted.append({
                        "id": f"{page_no}_{xref}",
                        "page": page_no,
                        "xref": xref,

                        # PDF-space geometry (points) - where image appears on page
                        "x": x,
                        "y": y,
                        "width": rect_width,
                        "height": rect_height,

                        # Original embedded image size (pixels) before any PDF or white-space crop
                        "original_width": orig_width,
                        "original_height": orig_height,

                        # Cropped image dimensions (matches visible size)
                        "image_width": final_width,
                        "image_height": final_height,

                        # Explicit cropped size in pixels (saved PNG dimensions; same as image_width/height when pdf_crop_applied)
                        "cropped_width": final_width,
                        "cropped_height": final_height,

                        "filename": filename,
                        "pdf_page_width": page.rect.width,
                        "pdf_page_height": page.rect.height,

                        # True when transformation matrix was available and used to crop to PDF-displayed region
                        "pdf_crop_applied": pdf_display_crop is not None,
                    })

                except Exception:
                    continue

        return extracted

    def apply_image_modifications(self, modifications):
        """
        Apply image modifications (move, resize, replace, crop, delete).

        Args:
            modifications: List of modification data for each image

        Returns:
            True if successful
        """
        import tempfile

        for mod in modifications:
            page_num = mod.get('page', 1) - 1
            if page_num < 0 or page_num >= len(self.doc):
                continue

            page = self.doc[page_num]

            # Get original position
            orig_x = mod.get('originalX', mod.get('x', 0))
            orig_y = mod.get('originalY', mod.get('y', 0))
            orig_width = mod.get('originalWidth', mod.get('width', 100))
            orig_height = mod.get('originalHeight', mod.get('height', 100))

            # Original rectangle to cover/remove
            original_rect = fitz.Rect(
                orig_x,
                orig_y,
                orig_x + orig_width,
                orig_y + orig_height
            )

            if mod.get('deleted'):
                # Remove image by covering with white rect
                page.draw_rect(original_rect, color=(1, 1, 1), fill=(1, 1, 1))
                continue

            # New position/size
            new_x = mod.get('x', orig_x)
            new_y = mod.get('y', orig_y)
            new_width = mod.get('width', orig_width)
            new_height = mod.get('height', orig_height)

            new_rect = fitz.Rect(
                new_x,
                new_y,
                new_x + new_width,
                new_y + new_height
            )

            if mod.get('replaced') and mod.get('replacementData'):
                # Cover original location with white
                page.draw_rect(original_rect, color=(1, 1, 1), fill=(1, 1, 1))

                # Decode and insert new image
                try:
                    replacement_data = mod['replacementData']
                    # Handle data URL format: data:image/png;base64,<data>
                    if replacement_data.startswith('data:'):
                        # Extract base64 part
                        base64_part = replacement_data.split(',', 1)[1]
                        img_data = base64.b64decode(base64_part)
                    else:
                        img_data = base64.b64decode(replacement_data)

                    # Write to temp file and insert
                    with tempfile.NamedTemporaryFile(delete=False, suffix='.png') as tmp:
                        tmp.write(img_data)
                        tmp.flush()
                        page.insert_image(new_rect, filename=tmp.name)
                        # Clean up temp file
                        Path(tmp.name).unlink(missing_ok=True)
                except Exception as e:
                    print(f"[ERROR] Failed to insert replacement image: {e}", file=sys.stderr)
                    continue

            elif self._image_was_modified(mod):
                # Image was moved, resized, or cropped but not replaced
                # We need to re-extract and re-insert the image

                # Cover original location with white
                page.draw_rect(original_rect, color=(1, 1, 1), fill=(1, 1, 1))

                # Try to extract the original image by xref
                xref = mod.get('xref')
                if xref:
                    try:
                        # Extract image data
                        img = self.doc.extract_image(xref)
                        if img:
                            img_data = img.get('image')
                            if img_data:
                                # Handle cropping if specified
                                crop_x = mod.get('cropX', 0)
                                crop_y = mod.get('cropY', 0)
                                crop_width = mod.get('cropWidth')
                                crop_height = mod.get('cropHeight')

                                # If crop is specified and different from full image, crop it
                                if crop_width and crop_height:
                                    try:
                                        from PIL import Image
                                        import io

                                        pil_img = Image.open(io.BytesIO(img_data))

                                        # Scale crop coordinates from display to image coordinates
                                        # Use the original full image dimensions, not the extracted dimensions
                                        img_w, img_h = pil_img.size
                                        # The crop coordinates from frontend are relative to the displayed image dimensions
                                        # We need to scale them to the original image coordinates
                                        display_width = mod.get('image_width', img_w)
                                        display_height = mod.get('image_height', img_h)
                                        scale_x = img_w / display_width if display_width else 1
                                        scale_y = img_h / display_height if display_height else 1

                                        crop_box = (
                                            int(crop_x * scale_x),
                                            int(crop_y * scale_y),
                                            int((crop_x + crop_width) * scale_x),
                                            int((crop_y + crop_height) * scale_y)
                                        )

                                        # Ensure crop box is within image bounds
                                        crop_box = (
                                            max(0, crop_box[0]),
                                            max(0, crop_box[1]),
                                            min(img_w, crop_box[2]),
                                            min(img_h, crop_box[3])
                                        )

                                        if crop_box[2] > crop_box[0] and crop_box[3] > crop_box[1]:
                                            cropped = pil_img.crop(crop_box)
                                            buffer = io.BytesIO()
                                            cropped.save(buffer, format='PNG')
                                            img_data = buffer.getvalue()
                                    except ImportError:
                                        # PIL not available, use original image
                                        pass
                                    except Exception:
                                        # Cropping failed, use original image
                                        pass

                                # Write to temp file and insert at new position
                                with tempfile.NamedTemporaryFile(delete=False, suffix='.png') as tmp:
                                    tmp.write(img_data)
                                    tmp.flush()
                                    page.insert_image(new_rect, filename=tmp.name)
                                    Path(tmp.name).unlink(missing_ok=True)
                    except Exception as e:
                        print(f"[ERROR] Failed to re-insert modified image: {e}", file=sys.stderr)
                        continue

        return True

    def _image_was_modified(self, mod: Dict[str, Any]) -> bool:
        """Check if an image was modified (moved, resized, or cropped)"""
        # Check if position changed
        if mod.get('x') != mod.get('originalX') or mod.get('y') != mod.get('originalY'):
            return True
        # Check if size changed
        if mod.get('width') != mod.get('originalWidth') or mod.get('height') != mod.get('originalHeight'):
            return True
        # Check if crop was applied
        if mod.get('cropX', 0) != 0 or mod.get('cropY', 0) != 0:
            return True
        return False

    def add_annotations(self, annotations: List[Dict[str, Any]]) -> bool:
        """
        Add annotations to PDF

        Args:
            annotations: List of annotation data

        Returns:
            True if successful
        """
        for annotation in annotations:
            page_num = annotation.get('page', 1) - 1

            if page_num < 0 or page_num >= len(self.doc):
                continue

            page = self.doc[page_num]
            # Scale factors: client used canvas pixels; PDF uses points. Map using provided baseCanvasWidth/Height if present.
            base_canvas_w = float(annotation.get('baseCanvasWidth') or 0) or float(page.rect.width)
            base_canvas_h = float(annotation.get('baseCanvasHeight') or 0) or float(page.rect.height)
            scale_x = float(page.rect.width) / base_canvas_w if base_canvas_w else 1.0
            scale_y = float(page.rect.height) / base_canvas_h if base_canvas_h else 1.0

            # Normalize common geometry fields to PDF coordinates
            def sx(v: float) -> float:
                return float(v) * scale_x

            def sy(v: float) -> float:
                return float(v) * scale_y
            annot_type = annotation.get('type')

            if annot_type == 'text':
                a = dict(annotation)
                a['x'] = sx(a.get('x', 0))
                a['y'] = sy(a.get('y', 0))
                a['fontSize'] = float(a.get('fontSize', 12)) * scale_y
                self._add_text_annotation(page, a)
            elif annot_type == 'stamp':
                a = dict(annotation)
                a['x'] = sx(a.get('x', 0))
                a['y'] = sy(a.get('y', 0))
                a['width'] = sx(a.get('width', 100))
                a['height'] = sy(a.get('height', 50))
                self._add_stamp_annotation(page, a)
            elif annot_type == 'highlight':
                a = dict(annotation)
                a['x'] = sx(a.get('x', 0))
                a['y'] = sy(a.get('y', 0))
                a['width'] = sx(a.get('width', 100))
                a['height'] = sy(a.get('height', 20))
                self._add_highlight_annotation(page, a)
            elif annot_type in ['rectangle', 'ellipse', 'line', 'arrow', 'polygon', 'polyline']:
                a = dict(annotation)
                if 'x' in a: a['x'] = sx(a.get('x', 0))
                if 'y' in a: a['y'] = sy(a.get('y', 0))
                if 'width' in a: a['width'] = sx(a.get('width', 100))
                if 'height' in a: a['height'] = sy(a.get('height', 100))
                if 'x1' in a: a['x1'] = sx(a.get('x1', 0))
                if 'y1' in a: a['y1'] = sy(a.get('y1', 0))
                if 'x2' in a: a['x2'] = sx(a.get('x2', 100))
                if 'y2' in a: a['y2'] = sy(a.get('y2', 100))
                if 'points' in a:
                    pts = a.get('points') or []
                    a['points'] = [{'x': sx(p.get('x', 0)), 'y': sy(p.get('y', 0))} for p in pts]
                self._add_shape_annotation(page, a)
            elif annot_type == 'signature':
                a = dict(annotation)
                paths = a.get('paths') or []
                scaled_paths = []
                for path in paths:
                    pts = path.get('points') or []
                    scaled_paths.append({'points': [{'x': sx(p.get('x', 0)), 'y': sy(p.get('y', 0))} for p in pts]})
                a['paths'] = scaled_paths
                self._add_signature_annotation(page, a)
            elif annot_type == 'drawing':
                a = dict(annotation)
                paths = a.get('paths') or []
                scaled_paths = []
                for path in paths:
                    pts = path.get('points') or []
                    scaled_paths.append({'points': [{'x': sx(p.get('x', 0)), 'y': sy(p.get('y', 0))} for p in pts]})
                a['paths'] = scaled_paths
                self._add_drawing_annotation(page, a)

        return True

    def _map_font_name(self, font_family: str, text: str) -> str:
        """
        Map Google Font names to PyMuPDF-compatible font names.
        For Japanese fonts, use PyMuPDF's built-in CJK fonts.

        Args:
            font_family: Font family name from frontend
            text: Text content to check for Japanese characters

        Returns:
            PyMuPDF-compatible font name
        """
        # Check if text contains Japanese characters (Hiragana, Katakana, Kanji)
        has_japanese = any(
            '\u3040' <= char <= '\u309F' or  # Hiragana
            '\u30A0' <= char <= '\u30FF' or  # Katakana
            '\u4E00' <= char <= '\u9FAF'     # CJK Unified Ideographs
            for char in text
        )

        # Font mapping: Google Font name -> PyMuPDF font name
        font_mapping = {
            # Western fonts (standard PDF fonts)
            'Helvetica': 'helv',
            'Arial': 'helv',  # Arial maps to Helvetica in PDF
            'Times New Roman': 'times',
            'Courier New': 'cour',
            'Georgia': 'times',  # Fallback to Times
            'Verdana': 'helv',  # Fallback to Helvetica

            # Google Fonts - Western
            'Roboto': 'helv',
            'Open Sans': 'helv',
            'Lato': 'helv',
            'Montserrat': 'helv',
            'Source Sans Pro': 'helv',
            'Poppins': 'helv',
            'Inter': 'helv',
            'Playfair Display': 'times',
            'Merriweather': 'times',

            # Japanese fonts - map to PyMuPDF CJK fonts
            'Noto Sans JP': 'japan' if has_japanese else 'helv',
            'Noto Serif JP': 'japan-s' if has_japanese else 'times',
            'M PLUS 1p': 'japan' if has_japanese else 'helv',
            'Kosugi Maru': 'japan' if has_japanese else 'helv',
            'Sawarabi Gothic': 'japan' if has_japanese else 'helv',
            'Sawarabi Mincho': 'japan-s' if has_japanese else 'times',
        }

        # Return mapped font or use original if not in mapping
        mapped_font = font_mapping.get(font_family, font_family)

        # If text contains Japanese but font doesn't support it, use Japanese font
        if has_japanese and mapped_font not in ('japan', 'japan-s'):
            # Determine if serif or sans-serif based on font name
            if 'serif' in font_family.lower() or 'mincho' in font_family.lower() or 'Mincho' in font_family:
                return 'japan-s'
            else:
                return 'japan'

        return mapped_font

    def _add_text_annotation(self, page: fitz.Page, data: Dict[str, Any]) -> None:
        """Add text annotation to page"""
        text = data.get('text', '')
        x = data.get('x', 0)
        y = data.get('y', 0)
        font_size = data.get('fontSize', 12)
        color = self._parse_color(data.get('color', '#000000'))
        font_family = data.get('fontFamily', 'Helvetica')

        # Map font name to PyMuPDF-compatible name
        font_name = self._map_font_name(font_family, text)

        # Create text rectangle
        rect = fitz.Rect(x, y, x + 200, y + font_size + 5)

        # Insert text
        page.insert_textbox(
            rect,
            text,
            fontsize=font_size,
            fontname=font_name,
            color=color,
        )

    def _add_stamp_annotation(self, page: fitz.Page, data: Dict[str, Any]) -> None:
        """Add stamp annotation to page"""
        x = data.get('x', 0)
        y = data.get('y', 0)
        width = data.get('width', 100)
        height = data.get('height', 50)

        # Check if this is an image-based stamp
        if 'imagePath' in data and data['imagePath']:
            image_path = data['imagePath']
            print(f"[DEBUG] Processing image: {image_path[:100]}", file=sys.stderr)
            try:
                from pathlib import Path
                from urllib.parse import unquote
                import urllib.parse
                import tempfile
                import requests  # type: ignore
                import re

                # Extract the path from URL (e.g., http://localhost:8094/images/stamps/stamp%20sample.png -> /images/stamps/stamp sample.png)
                parsed_url = urllib.parse.urlparse(image_path)
                local_path = unquote(parsed_url.path)
                print(f"[DEBUG] Parsed URL: scheme={parsed_url.scheme}, path={local_path}", file=sys.stderr)

                full_path = None
                if parsed_url.scheme == 'data':
                    # Handle data URL: data:[<mediatype>][;base64],<data>
                    try:
                        m = re.match(r"data:(?P<mime>[^;]+)?;base64,(?P<data>.+)", image_path)
                        if m:
                            b = base64.b64decode(m.group('data'))
                            suffix = '.png'
                            if m.group('mime') and '/' in m.group('mime'):
                                ext = m.group('mime').split('/')[-1]
                                if ext:
                                    suffix = '.' + ext
                            tmp = tempfile.NamedTemporaryFile(delete=False, suffix=suffix)
                            tmp.write(b)
                            tmp.flush()
                            tmp.close()
                            full_path = Path(tmp.name)
                    except Exception:
                        full_path = None
                elif parsed_url.scheme in ('http', 'https'):
                    # Download to temp file for insertion
                    try:
                        r = requests.get(image_path, timeout=10)
                        if r.status_code == 200:
                            tmp = tempfile.NamedTemporaryFile(delete=False, suffix=Path(local_path).suffix or '.png')
                            tmp.write(r.content)
                            tmp.flush()
                            tmp.close()
                            full_path = Path(tmp.name)
                    except Exception:
                        full_path = None
                else:
                    # Get the absolute path to the image under public/
                    base_path = Path(__file__).parent.parent
                    path_parts = local_path.lstrip('/').split('/')
                    full_path = base_path / 'public' / Path(*path_parts)

                if full_path and Path(full_path).exists():
                    print(f"[DEBUG] Using image file: {full_path}", file=sys.stderr)
                    rect = fitz.Rect(x, y, x + width, y + height)
                    page.insert_image(rect, filename=str(full_path))
                    return
                else:
                    print(f"[DEBUG] Image file not found: {full_path}", file=sys.stderr)
            except Exception as e:
                # Fallback to text stamp if image loading fails
                print(f"Image loading error in stamp: {type(e).__name__}: {e}", file=sys.stderr)
                raise  # Re-raise so calling code can log

        # Text-based stamp (legacy support)
        stamp_text = data.get('text', 'APPROVED')
        color = self._parse_color(data.get('color', '#00FF00'))

        rect = fitz.Rect(x, y, x + width, y + height)

        # Draw rectangle
        page.draw_rect(rect, color=color, width=2)

        # Add text
        text_rect = fitz.Rect(x + 5, y + 5, x + width - 5, y + height - 5)
        page.insert_textbox(
            text_rect,
            stamp_text,
            fontsize=14,
            fontname='helv',
            color=color,
            align=fitz.TEXT_ALIGN_CENTER,
        )

    def _add_highlight_annotation(self, page: fitz.Page, data: Dict[str, Any]) -> None:
        """Add highlight annotation to page"""
        x = data.get('x', 0)
        y = data.get('y', 0)
        width = data.get('width', 100)
        height = data.get('height', 20)
        color = self._parse_color(data.get('color', '#FFFF00'))

        rect = fitz.Rect(x, y, x + width, y + height)
        highlight = page.add_highlight_annot(rect)
        highlight.set_colors(stroke=color)
        highlight.update()

    def _add_shape_annotation(self, page: fitz.Page, data: Dict[str, Any]) -> None:
        """Add shape annotation to page"""
        shape_type = data.get('type')
        color = self._parse_color(data.get('strokeColor', '#000000'))
        fill_color = self._parse_color(data.get('fillColor', None))
        width = data.get('strokeWidth', 2)

        if shape_type == 'rectangle':
            x = data.get('x', 0)
            y = data.get('y', 0)
            w = data.get('width', 100)
            h = data.get('height', 100)
            rect = fitz.Rect(x, y, x + w, y + h)
            page.draw_rect(rect, color=color, fill=fill_color, width=width)

        elif shape_type == 'ellipse':
            x = data.get('x', 0)
            y = data.get('y', 0)
            w = data.get('width', 100)
            h = data.get('height', 100)
            rect = fitz.Rect(x, y, x + w, y + h)
            page.draw_oval(rect, color=color, fill=fill_color, width=width)

        elif shape_type == 'line':
            x1 = data.get('x1', 0)
            y1 = data.get('y1', 0)
            x2 = data.get('x2', 100)
            y2 = data.get('y2', 100)
            page.draw_line(fitz.Point(x1, y1), fitz.Point(x2, y2), color=color, width=width)

        elif shape_type == 'arrow':
            x1 = data.get('x1', 0)
            y1 = data.get('y1', 0)
            x2 = data.get('x2', 100)
            y2 = data.get('y2', 100)
            page.draw_line(fitz.Point(x1, y1), fitz.Point(x2, y2), color=color, width=width)
            # Add arrowhead
            self._draw_arrowhead(page, x1, y1, x2, y2, color, width)

        elif shape_type in ['polygon', 'polyline']:
            points = data.get('points', [])
            if len(points) >= 2:
                point_list = [fitz.Point(p['x'], p['y']) for p in points]
                if shape_type == 'polygon':
                    page.draw_polyline(point_list + [point_list[0]], color=color, fill=fill_color, width=width)
                else:
                    page.draw_polyline(point_list, color=color, width=width)

    def _add_signature_annotation(self, page: fitz.Page, data: Dict[str, Any]) -> None:
        """Add signature annotation (drawing paths)"""
        paths = data.get('paths', [])
        color = self._parse_color(data.get('color', '#0000FF'))
        width = data.get('width', 2)

        for path in paths:
            points = path.get('points', [])
            if len(points) >= 2:
                point_list = [fitz.Point(p['x'], p['y']) for p in points]
                page.draw_polyline(point_list, color=color, width=width)

    def _add_drawing_annotation(self, page: fitz.Page, data: Dict[str, Any]) -> None:
        """Add freehand drawing annotation"""
        self._add_signature_annotation(page, data)

    def _draw_arrowhead(self, page: fitz.Page, x1: float, y1: float, x2: float, y2: float,
                       color: tuple, width: float) -> None:
        """Draw arrowhead at the end of a line"""
        import math

        angle = math.atan2(y2 - y1, x2 - x1)
        arrow_length = 10
        arrow_angle = math.pi / 6

        # Calculate arrowhead points
        p1 = fitz.Point(
            x2 - arrow_length * math.cos(angle - arrow_angle),
            y2 - arrow_length * math.sin(angle - arrow_angle)
        )
        p2 = fitz.Point(
            x2 - arrow_length * math.cos(angle + arrow_angle),
            y2 - arrow_length * math.sin(angle + arrow_angle)
        )

        page.draw_line(fitz.Point(x2, y2), p1, color=color, width=width)
        page.draw_line(fitz.Point(x2, y2), p2, color=color, width=width)

    def _parse_color(self, color_str: str) -> tuple:
        """Parse color string to RGB tuple"""
        if color_str is None:
            return None

        # Remove # if present
        color_str = color_str.lstrip('#')

        # Convert hex to RGB (0-1 range for PyMuPDF)
        r = int(color_str[0:2], 16) / 255
        g = int(color_str[2:4], 16) / 255
        b = int(color_str[4:6], 16) / 255

        return (r, g, b)

    def save(self, output_path: str) -> bool:
        """
        Save the modified PDF

        Args:
            output_path: Path to save the PDF

        Returns:
            True if successful
        """
        try:
            self.doc.save(output_path, garbage=4, deflate=True)
            return True
        except Exception as e:
            raise ValueError(f"Failed to save PDF: {str(e)}")

    def close(self) -> None:
        """Close the PDF document"""
        try:
            # Avoid truthiness check which triggers __len__ on closed docs
            if getattr(self, 'doc', None) is not None:
                try:
                    self.doc.close()
                except Exception:
                    # Best effort close; ignore PyMuPDF state errors on already-closed docs
                    pass
        finally:
            # Ensure reference is dropped so __len__ is never called implicitly
            self.doc = None

    def __del__(self):
        """Cleanup on deletion"""
        try:
            self.close()
        except Exception:
            # Never let destructor raise
            pass


def main():
    """Main entry point for CLI usage"""
    if len(sys.argv) < 3:
        print(json.dumps({
            'error': 'Usage: python pdf_processor.py <command> <pdf_path> [args]'
        }))
        sys.exit(1)

    command = sys.argv[1]
    pdf_path = sys.argv[2]

    try:
        processor = PdfProcessor(pdf_path)

        if command == 'metadata':
            result = processor.get_metadata()
            print(json.dumps(result))

        elif command == 'thumbnails':
            output_dir = sys.argv[3] if len(sys.argv) > 3 else 'thumbnails'
            max_width = int(sys.argv[4]) if len(sys.argv) > 4 else 200
            result = processor.generate_thumbnails(output_dir, max_width)
            print(json.dumps(result))

        elif command == 'thumbnails_poppler':
            output_dir = sys.argv[3] if len(sys.argv) > 3 else 'thumbnails'
            max_width = int(sys.argv[4]) if len(sys.argv) > 4 else 200
            result = processor._pdftoppm_thumbnails(output_dir, max_width)
            print(json.dumps(result))

        elif command == 'render_page':
            page_num = int(sys.argv[3]) if len(sys.argv) > 3 else 0
            zoom = float(sys.argv[4]) if len(sys.argv) > 4 else 1.0
            result = processor.render_page(page_num, zoom)
            print(json.dumps(result))

        elif command == 'extract_images':
            output_dir = sys.argv[3] if len(sys.argv) > 3 else 'images'
            result = processor.extract_images(output_dir)
            print(json.dumps(result))

        elif command == 'apply_image_modifications':
            modifications_file = sys.argv[3]  # Path to file containing modifications JSON
            output_path = sys.argv[4]
            # Read modifications from file
            with open(modifications_file, 'r') as f:
                modifications = json.load(f)
            processor.apply_image_modifications(modifications)
            processor.save(output_path)
            print(json.dumps({'success': True, 'output': output_path}))

        elif command == 'add_annotations':
            annotations_file = sys.argv[3]  # Path to file containing annotations JSON
            output_path = sys.argv[4]
            # Read annotations from file instead of command-line argument
            with open(annotations_file, 'r') as f:
                annotations = json.load(f)
            processor.add_annotations(annotations)
            processor.save(output_path)
            print(json.dumps({'success': True, 'output': output_path}))

        else:
            print(json.dumps({'error': f'Unknown command: {command}'}))
            sys.exit(1)

        processor.close()

    except Exception as e:
        print(json.dumps({'error': str(e)}))
        sys.exit(1)


if __name__ == '__main__':
    main()

