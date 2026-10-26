# DitherBox

A PHP-based image dithering tool that converts images to various grayscale formats with customizable dithering algorithms.

## Features

- Multiple dithering algorithms:
  - Floyd-Steinberg (default)
  - Atkinson
  - Jarvis, Judice & Ninke
  - Stucki
  - Burkes
  - Sierra and Sierra Lite
  - Bayer 2x2, 4x4 and 8x8 ordered dithering
  - No dithering (simple quantization)
- Support for various output formats: PNG, JPG, GIF, PPM, PGM, PBM (1-bit PNG/GIF are written as small palette images)
- Customizable grayscale levels (1-8 bits)
- Adjustable output resolution
- Three image sources:
  - Direct URL input
  - File upload
  - Random images from curated collections:
    - Astronomy Picture of the Day (APOD)
    - This Is Colossal
    - Juxtapoz
    - Veri Artem
- Reduce color bleeding option for softer dithering effects, serpentine scanning
- Brightness, contrast, gamma, invert, rotate, and cover/contain fit
- EXIF orientation is honoured, transparent images are flattened onto white

## Requirements

- PHP 7.0 or higher
- GD Image Library extension
- cURL extension (remote fetching)
- EXIF extension (optional, for photo orientation)
- Internet access (for fetching images from URLs and RSS feeds)

## Installation

1. Clone or download this repository
2. Place the `index.php` file on a web server with PHP support
3. Ensure the GD and cURL extensions are enabled in your PHP configuration (EXIF is optional)
4. For a quick local try: `DTHBOX_ALLOW_PRIVATE=1 php -S localhost:8000 index.php`

## Usage

1. Access the tool through your web browser
2. Choose an image source:
   - **Via URL**: Enter the direct URL to an image
   - **Upload File**: Upload an image file from your device
   - **Random Collection**: Select a collection to fetch a random image
3. Configure the processing options:
   - **Output Format**: Select the desired output format
   - **Grayscale Bits**: Set the number of grayscale levels (1-8 bits)
   - **Dithering Method**: Choose the dithering algorithm
   - **Reduce Color Bleeding** and **Serpentine scanning**: Softer, more even dithering
   - **Brightness**, **Contrast**, **Gamma**, **Invert**: Tone adjustments applied before dithering
   - **Resolution**: Set the output dimensions (WxH, or a single number for the maximum size)
   - **Fit** and **Rotate**: Crop or pad to the target size, and rotate in 90° steps
4. Click "Process Image" to generate the dithered image

## API Usage

You can also use DitherBox programmatically by sending GET or POST requests with the following parameters:

- `url` - Image URL (for URL-based processing)
- `fmt` - Output format (png, jpg, gif, ppm, pgm, pbm)
- `bits` - Grayscale bits (1-8)
- `res` - Resolution (WxH format or single number for max size)
- `dth` - Dithering method (fs, ak, jv, sk, bk, sr, sl, by, by4, by8, none)
- `rb` - Reduce bleeding (1 for true, 0 for false, default 1)
- `serp` - Serpentine scanning (0/1, default 0)
- `brightness`, `contrast` - -100 to 100 (default 0)
- `gamma` - 0.1 to 5 (default 1; above 1 brightens midtones)
- `invert` - Invert the image (0/1)
- `rotate` - Clockwise rotation: 0, 90, 180, 270
- `fit` - `cover` (crop, default) or `contain` (pad with white); ignored when `res` is a single number
- `image` (POST, multipart) - uploaded image file; a raw image in the POST body also works
- `ui` (POST) - set to 1 to get the HTML result page instead of the raw image
- `col` - Collection for random images (apod, tic, jux, veri, any)

Example:
```
GET /index.php?url=https://example.com/image.jpg&bits=2&dth=fs&fmt=png
```

### Using curl

You can also use curl to process images from the command line:

```bash
# Process an image from a URL and get the result directly
curl "http://localhost/index.php?url=https://example.com/image.jpg&bits=2&dth=fs&fmt=png" -o output.png

# Upload a local image file and get the result directly
curl -F "image=@local_image.jpg" -F "bits=3" -F "dth=ak" -F "fmt=png" http://localhost/index.php -o output.png

# Get a random image from a collection and get the result directly
curl "http://localhost/index.php?col=apod&bits=1&dth=jv&fmt=gif" -o output.gif

# Get a random image from a collection for an epaper display
curl "http://localhost/index.php?col=any&bits=1&dth=jv&fmt=pbm&res=296x128" -o output.pbm

# 2-bit Bayer 4x4, higher contrast, rotated, padded instead of cropped
curl "http://localhost/index.php?url=https://example.com/image.jpg&bits=2&dth=by4&contrast=30&rotate=90&fit=contain&res=400x300" -o output.png

# Send the raw image in the request body
curl --data-binary @local_image.jpg "http://localhost/index.php?bits=1&dth=sr&fmt=png" -o output.png
```

## Security and limits

- Only `http`/`https` URLs are fetched, hosts resolving to private/loopback/reserved addresses are refused
  (checked again on every redirect), downloads are limited to 15 MiB and 20 s.
- Source images over 40 megapixels are rejected; output is limited to 2000x2000.
- Requests are rate limited per IP (default 60 per minute). Set the `DTHBOX_RATE` environment variable to change it
  (`0` disables). Set `DTHBOX_ALLOW_PRIVATE=1` to allow fetching from private addresses (e.g. local testing).
- RSS feed image lists are cached in the system temp directory for 30 minutes.
- Errors are returned with proper HTTP status codes (400, 413, 415, 429, 502, ...).

## Tests

The dithering core, encoders, URL validation and feed parsing do not need GD and can be tested with:

```bash
php tests/run.php
```

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

## Author

Costin Stroie - [costinstroie@eridu.eu.org](mailto:costinstroie@eridu.eu.org)

Project Link: [https://github.com/cstroie/dthbox](https://github.com/cstroie/dthbox)
