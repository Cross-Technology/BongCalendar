#!/usr/bin/env bash
#
# Generates every PWA / favicon asset from one square source image.
#
#   ./scripts/generate-icons.sh public/logo.png
#   ./scripts/generate-icons.sh public/logo.png --keep-background
#
# Re-run it whenever the brand mark changes; it overwrites in place.
set -euo pipefail

SRC="${1:-public/logo.png}"
KEEP_BACKGROUND="${2:-}"
OUT="public/icons"
MASTER_SIZE=1024

# Colour the icons that cannot carry transparency are flattened onto: Android's
# maskable icons and every Apple touch icon.
BG="white"

# Maskable icons are cropped by Android to a circle, squircle or teardrop. Only
# the middle 80% — a 410px circle on a 512px canvas — is guaranteed to survive,
# so the artwork is fitted into that box.
MASKABLE_SCALE=80

# Before fitting, the outer 15% is cut away. A logo drawn as a rounded card
# leaves a faint anti-aliased edge once its backdrop is stripped; flattened onto
# white that edge becomes a grey outline, and inset into the safe zone it lands
# *inside* Android's crop where it reads as a ring around the icon.
#
# The cut has to clear the card's corner radius, not just the edge: a crop whose
# corner still falls on the curve trades the ring for four little nicks. For a
# radius r, the crop must come in at least r(1 - 1/√2) ≈ 0.29r from each side —
# about 66px on this 1024px master — so 15% is comfortably past it and lands on
# the card's flat interior, which blends into the fill exactly.
MASKABLE_CROP=85

if [ ! -f "$SRC" ]; then
    echo "error: source image not found: $SRC" >&2
    exit 1
fi

if ! command -v magick >/dev/null 2>&1; then
    echo "error: ImageMagick (magick) is required — brew install imagemagick" >&2
    exit 1
fi

mkdir -p "$OUT"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
MASTER="$TMP/master.png"

# Everything downstream works from one high-resolution raster master. That
# keeps the resizing identical for every source type — and, for SVG, routes
# around ImageMagick's internal renderer, which silently drops strokes and
# transforms and hands back a blank square with no error.
case "$(printf '%s' "${SRC##*.}" | tr '[:upper:]' '[:lower:]')" in
    svg | svgz)
        if ! command -v rsvg-convert >/dev/null 2>&1; then
            echo "error: SVG source needs rsvg-convert — brew install librsvg" >&2
            echo "       (ImageMagick's built-in SVG renderer produces blank icons)" >&2
            exit 1
        fi
        rsvg-convert -w "$MASTER_SIZE" -h "$MASTER_SIZE" "$SRC" -o "$MASTER"
        ;;
    *)
        magick "$SRC" -resize "${MASTER_SIZE}x${MASTER_SIZE}" -gravity center \
            -background none -extent "${MASTER_SIZE}x${MASTER_SIZE}" "PNG32:$MASTER"
        ;;
esac

if [ "$(magick identify -format '%k' "$MASTER")" -lt 2 ]; then
    echo "error: '$SRC' rendered as a blank image — nothing to generate from" >&2
    exit 1
fi

echo "source: $SRC"

# ── Drop a flat background behind a rounded logo ─────────────────────────────
# A logo exported as a rounded card on a solid backdrop keeps that backdrop as
# four hard corners. Left alone they become black triangles on every icon, so
# when all four corners share a colour they are flood-filled to transparency.
# Flood fill only reaches the connected border region, so artwork of the same
# colour inside the mark is untouched. Pass --keep-background to skip this.
if [ "$KEEP_BACKGROUND" != "--keep-background" ]; then
    EDGE=$((MASTER_SIZE - 1))

    # Read a corner as four 0-255 integers.
    corner() {
        magick "$MASTER" -format \
            "%[fx:int(255*p{$1,$2}.r)] %[fx:int(255*p{$1,$2}.g)] %[fx:int(255*p{$1,$2}.b)] %[fx:int(255*p{$1,$2}.a)]" \
            info:
    }

    # Widest gap between the values it is given.
    spread() {
        local max=$1 min=$1 v
        for v in "$@"; do
            [ "$v" -gt "$max" ] && max=$v
            [ "$v" -lt "$min" ] && min=$v
        done
        echo $((max - min))
    }

    rs=(); gs=(); bs=(); as=()

    for xy in "0 0" "$EDGE 0" "0 $EDGE" "$EDGE $EDGE"; do
        # shellcheck disable=SC2086
        read -r r g b a <<<"$(corner $xy)"
        rs+=("$r"); gs+=("$g"); bs+=("$b"); as+=("$a")
    done

    # Compared with a tolerance rather than for equality: downsampling the
    # source to the master shifts each corner by a point or two, so an exact
    # match silently never fires and every icon keeps its black triangles.
    if [ "$(spread "${rs[@]}")" -le 8 ] &&
       [ "$(spread "${gs[@]}")" -le 8 ] &&
       [ "$(spread "${bs[@]}")" -le 8 ] &&
       [ "${as[0]}" -gt 128 ]; then
        magick "$MASTER" -alpha set -fuzz 25% \
            -fill none \
            -draw 'alpha 0,0 floodfill' \
            -draw "alpha $EDGE,0 floodfill" \
            -draw "alpha 0,$EDGE floodfill" \
            -draw "alpha $EDGE,$EDGE floodfill" \
            "PNG32:$TMP/stripped.png"
        mv "$TMP/stripped.png" "$MASTER"
        printf '  removed flat background: rgb(%s,%s,%s)\n' "${rs[0]}" "${gs[0]}" "${bs[0]}"
    fi
fi

# ── Standard icons: transparency preserved, square canvas ────────────────────
for size in 32 48 96 128 192 256 384 512; do
    magick "$MASTER" -resize "${size}x${size}" -gravity center -background none \
        -extent "${size}x${size}" "PNG32:$OUT/icon-${size}.png"
done
echo "  icon-{32,48,96,128,192,256,384,512}.png"

# ── Maskable icons, inset into Android's safe zone ──────────────────────────
for size in 192 512; do
    inner=$(( size * MASKABLE_SCALE / 100 ))
    magick "$MASTER" -background "$BG" -alpha remove -alpha off \
        -gravity center -crop "${MASKABLE_CROP}%x${MASKABLE_CROP}%+0+0" +repage \
        -resize "${inner}x${inner}" -gravity center -background "$BG" \
        -extent "${size}x${size}" -alpha remove -alpha off "PNG32:$OUT/maskable-${size}.png"
done
echo "  maskable-{192,512}.png (artwork at ${MASKABLE_SCALE}%, edge cropped to ${MASKABLE_CROP}%)"

# ── Apple touch icons ───────────────────────────────────────────────────────
# iOS applies its own rounded mask and does not composite transparency, so
# these are flattened and fill the full square.
for size in 152 167 180; do
    magick "$MASTER" -resize "${size}x${size}" -gravity center -background "$BG" \
        -extent "${size}x${size}" -alpha remove -alpha off "PNG32:$OUT/apple-touch-icon-${size}.png"
done
cp "$OUT/apple-touch-icon-180.png" "$OUT/apple-touch-icon.png"
echo "  apple-touch-icon.png (+152, 167, 180)"

# ── Favicon: one .ico carrying 16/32/48 ─────────────────────────────────────
magick "$MASTER" -resize 48x48 -gravity center -background none -extent 48x48 \
    -define icon:auto-resize=48,32,16 public/favicon.ico
echo "  favicon.ico (16/32/48)"

# ── Verify nothing came out blank ───────────────────────────────────────────
blank=0
for f in "$OUT"/*.png; do
    if [ "$(magick identify -format '%k' "$f")" -lt 2 ]; then
        echo "  WARNING: $f is a single flat colour" >&2
        blank=$((blank + 1))
    fi
done

if [ "$blank" -gt 0 ]; then
    echo "error: $blank icon(s) came out blank" >&2
    exit 1
fi

echo "done — $(find "$OUT" -type f -name '*.png' | wc -l | tr -d ' ') icons verified in $OUT"
