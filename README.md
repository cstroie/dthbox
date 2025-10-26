# DitherBox

A PHP-based image dithering tool that converts images to various grayscale formats with customizable dithering algorithms.

## Features

- Multiple dithering algorithms:
  - Floyd-Steinberg (default)
  - Atkinson
  - Jarvis, Judice & Ninke
  - Stucki
  - Burkes
  - Bayer 2x2 ordered dithering
  - No dithering (simple quantization)
- Support for various output formats: PNG, JPG, PPM, PBM, GIF
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
- Reduce color bleeding option for softer dithering effects

## Requirements

- PHP 7.0 or higher
- GD Image Library extension
- Internet access (for fetching images from URLs and RSS feeds)

## Installation

1. Clone or download this repository
2. Place the `index.php` file on a web server with PHP support
3. Ensure the GD library is enabled in your PHP configuration

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
   - **Reduce Color Bleeding**: Enable for softer dithering effects
   - **Resolution**: Set the output dimensions (WxH format)
4. Click "Process Image" to generate the dithered image

## API Usage

You can also use DitherBox programmatically by sending GET or POST requests with the following parameters:

- `url` - Image URL (for URL-based processing)
- `fmt` - Output format (png, jpg, ppm, pbm, gif)
- `bits` - Grayscale bits (1-8)
- `res` - Resolution (WxH format or single number for max size)
- `dth` - Dithering method (fs, ak, jv, sk, bk, by, none)
- `rb` - Reduce bleeding (1 for true, 0 for false)
- `col` - Collection for random images (apod, tic, jux, veri, any)

Example:
```
GET /index.php?url=https://example.com/image.jpg&bits=2&dth=fs&fmt=png
```

### Using curl

You can also use curl to process images from the command line:

```bash
# Process an image from a URL
curl "http://localhost/index.php?url=https://example.com/image.jpg&bits=2&dth=fs&fmt=png" -o output.png

# Upload a local image file
curl -F "image=@local_image.jpg" -F "bits=3" -F "dth=ak" -F "fmt=png" http://localhost/index.php -o output.png

# Get a random image from a collection
curl "http://localhost/index.php?col=apod&bits=1&dth=by&fmt=gif" -o output.gif
```

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

## Author

Costin Stroie - [costinstroie@eridu.eu.org](mailto:costinstroie@eridu.eu.org)

Project Link: [https://github.com/cstroie/dthbox](https://github.com/cstroie/dthbox)
