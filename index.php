<?php
// Fetch and process art images from specified collection
// Then crop and scale it to 296x128 format and return in specified format

// Check if this is a POST request with image data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get parameters from POST data or use defaults
    $fmt = isset($_POST['fmt']) ? strtolower($_POST['fmt']) : 'png';
    $bits = isset($_POST['bits']) ? intval($_POST['bits']) : 1;
    $res = isset($_POST['res']) ? $_POST['res'] : '296x128';
    $ditherMethod = isset($_POST['dth']) ? $_POST['dth'] : 'fs';
    $reduceBleeding = isset($_POST['rb']) ? (bool)$_POST['rb'] : true;
        
    // Validate and parse resolution
    if (preg_match('/^(\d+)x(\d+)$/', $res, $matches)) {
        $targetWidth = intval($matches[1]);
        $targetHeight = intval($matches[2]);
        // Ensure reasonable limits to prevent abuse
        $targetWidth = max(1, min(2000, $targetWidth));
        $targetHeight = max(1, min(2000, $targetHeight));
    } else if (is_numeric($res)) {
        // If res is a single number, treat it as maximum size
        $maxSize = max(1, min(2000, intval($res)));
        // We'll determine actual dimensions after loading the image
        $targetWidth = $maxSize;
        $targetHeight = $maxSize;
        $useMaxSize = true;
    } else {
        // Default to 400x300 if invalid format
        $targetWidth = 400;
        $targetHeight = 300;
    }
        
    // Clamp bits between 1 and 8
    $bits = max(1, min(8, $bits));
    // Convert bits to levels
    $levels = pow(2, $bits);
        
    // Get allowed formats
    $formats = ['png', 'jpg', 'jpeg', 'ppm', 'pbm', 'gif'];
    if (!in_array($fmt, $formats)) {
        // Default to png if invalid format
        $fmt = 'png';
    }
        
    // Check for URL parameter in POST data
    $imageUrl = isset($_POST['url']) ? $_POST['url'] : null;
} else {
    // Check for URL parameter in GET data
    $imageUrl = isset($_GET['url']) ? $_GET['url'] : null;
        
    // Get collection from query parameter, default to random selection
    $col = isset($_GET['col']) ? strtolower($_GET['col']) : 'any';
    global $collections;
    $collections = ['apod', 'tic', 'jux', 'veri'];

    // If collection is 'any' or not specified, choose randomly from available collections
    // But only if no URL is provided
    if (!$imageUrl && ($col === 'any' || !in_array($col, $collections))) {
        $col = $collections[array_rand($collections)];
    }

    // Get format from query parameter, default to png
    $fmt = isset($_GET['fmt']) ? strtolower($_GET['fmt']) : 'png';
    $formats = ['png', 'jpg', 'jpeg', 'ppm', 'pbm', 'gif'];
    if (!in_array($fmt, $formats)) {
        // Default to png if invalid format
        $fmt = 'png'; 
    }

    // Get grayscale bits from query parameter, default to 1 (minimal grayscale)
    $bits = isset($_GET['bits']) ? intval($_GET['bits']) : 1;
    // Clamp bits between 1 and 8
    $bits = max(1, min(8, $bits));
    // Convert bits to levels
    $levels = pow(2, $bits);

    // Get resolution from query parameter, default to 296x128
    $res = isset($_GET['res']) ? $_GET['res'] : '296x128';
    // Validate and parse resolution
    if (preg_match('/^(\d+)x(\d+)$/', $res, $matches)) {
        $targetWidth = intval($matches[1]);
        $targetHeight = intval($matches[2]);
        // Ensure reasonable limits to prevent abuse
        $targetWidth = max(1, min(2000, $targetWidth));
        $targetHeight = max(1, min(2000, $targetHeight));
    } else if (is_numeric($res)) {
        // If res is a single number, treat it as maximum size
        $maxSize = max(1, min(2000, intval($res)));
        // We'll determine actual dimensions after loading the image
        $targetWidth = $maxSize;
        $targetHeight = $maxSize;
        $useMaxSize = true;
    } else {
        // Default to 296x128 if invalid format
        $targetWidth = 296;
        $targetHeight = 128;
    }
        
    // Get dithering parameters
    $dth = isset($_GET['dth']) ? $_GET['dth'] : 'fs';
    $rb = isset($_GET['rb']) ? (bool)$_GET['rb'] : true;
        
    // If no 'col' or 'url' are provided, show the upload form
    if (!isset($_GET['col']) && !isset($_GET['url']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        showUploadForm();
        exit;
    }
}

function showUploadForm() {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DitherBox</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
        <style>
            .container {
                max-width: 800px;
                margin: 2rem auto;
                padding: 0 1rem;
            }
            .note {
                font-size: 0.9rem;
                color: var(--pico-muted-color);
            }
            .form-section {
                margin-bottom: 1.5rem;
                padding: 1rem;
                border: 1px solid var(--pico-muted-border-color);
                border-radius: 0.25rem;
            }
            .form-section h3 {
                margin-top: 0;
            }
        </style>
    </head>
    <body>
        <main class="container">
            <h1>DitherBox</h1>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <!-- Image Source Section -->
                <div class="form-section">
                    <h3>Image Source</h3>
                    
                    <!-- URL Input -->
                    <div>
                        <label for="url">Image URL:</label>
                        <input type="url" id="url" name="url" placeholder="https://example.com/image.jpg">
                    </div>
                    
                    <!-- File Upload -->
                    <div>
                        <label for="image">Upload Image File:</label>
                        <input type="file" id="image" name="image" accept="image/*">
                    </div>
                    
                    <!-- Collection Selection -->
                    <div>
                        <label for="col">Random Collection:</label>
                        <select id="col" name="col">
                            <option value="">-- Select Collection --</option>
                            <option value="apod">Astronomy Picture of the Day</option>
                            <option value="tic">This Is Colossal</option>
                            <option value="jux">Juxtapoz</option>
                            <option value="veri">Veri Artem</option>
                            <option value="any">Random Collection</option>
                        </select>
                    </div>
                </div>
                
                <!-- Processing Options Section -->
                <div class="form-section">
                    <h3>Processing Options</h3>
                    
                    <!-- Output Format -->
                    <div>
                        <label for="fmt">Output Format:</label>
                        <select id="fmt" name="fmt">
                            <option value="png">PNG</option>
                            <option value="jpg">JPG</option>
                            <option value="ppm">PPM</option>
                            <option value="pbm">PBM</option>
                            <option value="gif">GIF</option>
                        </select>
                    </div>
                    
                    <!-- Grayscale Bits -->
                    <div>
                        <label for="bits">Grayscale Bits: <span id="bits_value">1</span></label>
                        <input type="range" id="bits" name="bits" min="1" max="8" value="1" oninput="document.getElementById('bits_value').textContent = this.value">
                    </div>
                    
                    <!-- Dithering Method -->
                    <div>
                        <label for="dth">Dithering Method:</label>
                        <select id="dth" name="dth">
                            <option value="none">None</option>
                            <option value="fs" selected>Floyd-Steinberg</option>
                            <option value="ak">Atkinson</option>
                            <option value="jv">Jarvis, Judice & Ninke</option>
                            <option value="sk">Stucki</option>
                            <option value="bk">Burkes</option>
                            <option value="by">Bayer 2x2</option>
                        </select>
                    </div>
                    
                    <!-- Reduce Color Bleeding -->
                    <div>
                        <label>
                            <input type="checkbox" id="rb" name="rb" value="1" checked>
                            Reduce Color Bleeding
                        </label>
                    </div>
                    
                    <!-- Resolution -->
                    <div>
                        <label for="res">Resolution (WxH):</label>
                        <input type="text" id="res" name="res" value="296x128" placeholder="296x128">
                    </div>
                </div>
                
                <!-- Submit Button -->
                <input type="submit" value="Process Image">
            </form>
            
            <p class="note">Note: DitherBox processes images with customizable dithering and grayscale levels. Provide either a URL, upload a file, or select a collection.</p>
        </main>
    </body>
    </html>
    <?php
}

function fetchRandomApodImage() {
    // Fetch the RSS feed
    $rssUrl = 'https://apod.com/feed.rss';
    $rssContent = file_get_contents($rssUrl);
    
    if ($rssContent === false) {
        throw new Exception('Failed to fetch RSS feed');
    }
    
    // Parse the RSS feed
    $rss = simplexml_load_string($rssContent);
    
    if ($rss === false) {
        throw new Exception('Failed to parse RSS feed');
    }
    
    // Extract image URLs from enclosures
    $imageUrls = [];
    foreach ($rss->channel->item as $item) {
        foreach ($item->enclosure as $enclosure) {
            $url = (string)$enclosure['url'];
            // Only include JPG images
            if (preg_match('/\.(jpg|jpeg)$/i', $url)) {
                $imageUrls[] = $url;
            }
        }
    }
    
    // If no JPG images found, try to extract from description
    if (empty($imageUrls)) {
        foreach ($rss->channel->item as $item) {
            $description = (string)$item->description;
            // Look for img tags in description
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+\.(jpg|jpeg))["\'][^>]*>/i', $description, $matches)) {
                $imageUrls = array_merge($imageUrls, $matches[1]);
            }
        }
    }
    
    if (empty($imageUrls)) {
        throw new Exception('No JPG images found in RSS feed');
    }
    
    // Select a random image
    $randomImageUrl = $imageUrls[array_rand($imageUrls)];
    
    // Fetch the image
    $imageData = file_get_contents($randomImageUrl);
    
    if ($imageData === false) {
        throw new Exception('Failed to fetch image');
    }
    
    return $imageData;
}

function fetchRandomTicImage() {
    // Fetch the RSS feed
    $rssUrl = 'https://www.thisiscolossal.com/feed/';
    $rssContent = file_get_contents($rssUrl);
    
    if ($rssContent === false) {
        throw new Exception('Failed to fetch TIC RSS feed');
    }
    
    // Parse the RSS feed
    $rss = simplexml_load_string($rssContent);
    
    if ($rss === false) {
        throw new Exception('Failed to parse TIC RSS feed');
    }
    
    // Extract image URLs from enclosures
    $imageUrls = [];
    foreach ($rss->channel->item as $item) {
        foreach ($item->enclosure as $enclosure) {
            $url = (string)$enclosure['url'];
            // Only include JPG images
            if (preg_match('/\.(jpg|jpeg)$/i', $url)) {
                $imageUrls[] = $url;
            }
        }
    }
    
    // If no JPG images found, try to extract from description
    if (empty($imageUrls)) {
        foreach ($rss->channel->item as $item) {
            $description = (string)$item->description;
            // Look for img tags in description
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+\.(jpg|jpeg))["\'][^>]*>/i', $description, $matches)) {
                $imageUrls = array_merge($imageUrls, $matches[1]);
            }
        }
    }
    
    if (empty($imageUrls)) {
        throw new Exception('No JPG images found in TIC RSS feed');
    }
    
    // Select a random image
    $randomImageUrl = $imageUrls[array_rand($imageUrls)];
    
    // Fetch the image
    $imageData = file_get_contents($randomImageUrl);
    
    if ($imageData === false) {
        throw new Exception('Failed to fetch image from TIC');
    }
    
    return $imageData;
}

function fetchRandomJuxImage() {
    // Fetch the RSS feed
    $rssUrl = 'https://www.juxtapoz.com/news/?format=feed&type=rss';
    $rssContent = file_get_contents($rssUrl);
    
    if ($rssContent === false) {
        throw new Exception('Failed to fetch JUX RSS feed');
    }
    
    // Parse the RSS feed
    $rss = simplexml_load_string($rssContent);
    
    if ($rss === false) {
        throw new Exception('Failed to parse JUX RSS feed');
    }
    
    // Extract image URLs from enclosures
    $imageUrls = [];
    foreach ($rss->channel->item as $item) {
        foreach ($item->enclosure as $enclosure) {
            $url = (string)$enclosure['url'];
            // Only include JPG images
            if (preg_match('/\.(jpg|jpeg)$/i', $url)) {
                $imageUrls[] = $url;
            }
        }
    }
    
    // If no JPG images found, try to extract from description
    if (empty($imageUrls)) {
        foreach ($rss->channel->item as $item) {
            $description = (string)$item->description;
            // Look for img tags in description
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+\.(jpg|jpeg))["\'][^>]*>/i', $description, $matches)) {
                $imageUrls = array_merge($imageUrls, $matches[1]);
            }
        }
    }
    
    if (empty($imageUrls)) {
        throw new Exception('No JPG images found in JUX RSS feed');
    }
    
    // Select a random image
    $randomImageUrl = $imageUrls[array_rand($imageUrls)];
    
    // Fetch the image
    $imageData = file_get_contents($randomImageUrl);
    
    if ($imageData === false) {
        throw new Exception('Failed to fetch image from JUX');
    }
    
    return $imageData;
}

function fetchRandomVeriImage() {
    // Fetch the RSS feed
    $rssUrl = 'https://veriartem.com/feed/';
    $rssContent = file_get_contents($rssUrl);
    
    if ($rssContent === false) {
        throw new Exception('Failed to fetch VERI RSS feed');
    }
    
    // Parse the RSS feed
    $rss = simplexml_load_string($rssContent);
    
    if ($rss === false) {
        throw new Exception('Failed to parse VERI RSS feed');
    }
    
    // Extract image URLs from enclosures
    $imageUrls = [];
    foreach ($rss->channel->item as $item) {
        foreach ($item->enclosure as $enclosure) {
            $url = (string)$enclosure['url'];
            // Only include JPG images
            if (preg_match('/\.(jpg|jpeg)$/i', $url)) {
                $imageUrls[] = $url;
            }
        }
    }
    
    // If no JPG images found, try to extract from description
    if (empty($imageUrls)) {
        foreach ($rss->channel->item as $item) {
            $description = (string)$item->description;
            // Look for img tags in description
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+\.(jpg|jpeg))["\'][^>]*>/i', $description, $matches)) {
                $imageUrls = array_merge($imageUrls, $matches[1]);
            }
        }
    }
    
    if (empty($imageUrls)) {
        throw new Exception('No JPG images found in VERI RSS feed');
    }
    
    // Select a random image
    $randomImageUrl = $imageUrls[array_rand($imageUrls)];
    
    // Fetch the image
    $imageData = file_get_contents($randomImageUrl);
    
    if ($imageData === false) {
        throw new Exception('Failed to fetch image from VERI');
    }
    
    return $imageData;
}

function fetchRandomImage($collection) {
    switch ($collection) {
        case 'tic':
            return fetchRandomTicImage();
        case 'jux':
            return fetchRandomJuxImage();
        case 'veri':
            return fetchRandomVeriImage();
        case 'apod':
        default:
            return fetchRandomApodImage();
    }
}

function processImage($imageData, $levels, $targetWidth, $targetHeight, $dth, $rb, $useMaxSize = false) {
    // Create image from data
    $srcImage = imagecreatefromstring($imageData);
    
    if ($srcImage === false) {
        throw new Exception('Failed to create image from data');
    }
    
    // Get original dimensions
    $srcWidth = imagesx($srcImage);
    $srcHeight = imagesy($srcImage);
    
    // If using max size, calculate target dimensions while maintaining aspect ratio
    if ($useMaxSize) {
        $maxSize = $targetWidth; // Both width and height are set to the same max value
        if ($srcWidth > $srcHeight) {
            // Landscape image
            $targetWidth = $maxSize;
            $targetHeight = intval($srcHeight * $maxSize / $srcWidth);
        } else {
            // Portrait or square image
            $targetHeight = $maxSize;
            $targetWidth = intval($srcWidth * $maxSize / $srcHeight);
        }
        // Ensure dimensions are at least 1
        $targetWidth = max(1, $targetWidth);
        $targetHeight = max(1, $targetHeight);
        
        // Set crop dimensions to full image (no cropping)
        $cropWidth = $srcWidth;
        $cropHeight = $srcHeight;
        $srcX = 0;
        $srcY = 0;
    } else {
        // Calculate crop dimensions to maintain aspect ratio
        $srcRatio = $srcWidth / $srcHeight;
        $targetRatio = $targetWidth / $targetHeight;
        
        if ($srcRatio > $targetRatio) {
            // Source is wider, crop width
            $cropWidth = $srcHeight * $targetRatio;
            $cropHeight = $srcHeight;
            $srcX = ($srcWidth - $cropWidth) / 2;
            $srcY = 0;
        } else {
            // Source is taller, crop height
            $cropWidth = $srcWidth;
            $cropHeight = $srcWidth / $targetRatio;
            $srcX = 0;
            $srcY = ($srcHeight - $cropHeight) / 2;
        }
    }
    
    // Create destination image
    $dstImage = imagecreatetruecolor($targetWidth, $targetHeight);
    
    // Resize and crop
    imagecopyresampled(
        $dstImage, $srcImage,
        0, 0, $srcX, $srcY,
        $targetWidth, $targetHeight,
        $cropWidth, $cropHeight
    );
    
    // Convert to grayscale
    imagefilter($dstImage, IMG_FILTER_GRAYSCALE);
    
    // Apply dithering based on selected method for low levels, otherwise simple quantization
    if ($levels < 256 && $dth !== 'none') {
        switch ($dth) {
            case 'fs':
                floydSteinbergDither($dstImage, $levels, $rb);
                break;
            case 'ak':
                atkinsonDither($dstImage, $levels, $rb);
                break;
            case 'jv':
                jarvisDither($dstImage, $levels, $rb);
                break;
            case 'sk':
                stuckiDither($dstImage, $levels, $rb);
                break;
            case 'bk':
                burkesDither($dstImage, $levels, $rb);
                break;
            case 'by':
                bayer2x2Dither($dstImage, $levels);
                break;
            default:
                // Simple quantization for unknown methods
                $step = 255 / ($levels - 1);
                for ($y = 0; $y < $targetHeight; $y++) {
                    for ($x = 0; $x < $targetWidth; $x++) {
                        $rgb = imagecolorat($dstImage, $x, $y);
                        $gray = ($rgb >> 16) & 0xFF; // Get grayscale value
                        
                        // Quantize to specified number of levels
                        $quantized = round(round($gray / $step) * $step);
                        // Clamp to valid range
                        $quantized = max(0, min(255, $quantized));
                        
                        $newColor = imagecolorallocate($dstImage, $quantized, $quantized, $quantized);
                        imagesetpixel($dstImage, $x, $y, $newColor);
                    }
                }
                break;
        }
    } else if ($levels < 256) {
        // Simple quantization when dithering is disabled
        $step = 255 / ($levels - 1);
        for ($y = 0; $y < $targetHeight; $y++) {
            for ($x = 0; $x < $targetWidth; $x++) {
                $rgb = imagecolorat($dstImage, $x, $y);
                $gray = ($rgb >> 16) & 0xFF; // Get grayscale value
                
                // Quantize to specified number of levels
                $quantized = round(round($gray / $step) * $step);
                // Clamp to valid range
                $quantized = max(0, min(255, $quantized));
                
                $newColor = imagecolorallocate($dstImage, $quantized, $quantized, $quantized);
                imagesetpixel($dstImage, $x, $y, $newColor);
            }
        }
    }
    
    // Clean up source image
    imagedestroy($srcImage);
    
    return $dstImage;
}

function floydSteinbergDither($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffer
    $errors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        $nextErrors = array_fill(0, $width, 0);
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $errors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            if ($rb) {
                // Distribute error using reduced Floyd-Steinberg coefficients
                // Reduce bleeding by using smaller fractions (half the original values)
                // Current pixel: 0 (already processed)
                // Right pixel: 7/32 (instead of 7/16)
                // Below left: 3/32 (instead of 3/16), Below: 5/32 (instead of 5/16), Below right: 1/32 (instead of 1/16)
                
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * (7/32);
                }
                
                if ($y + 1 < $height) {
                    if ($x > 0) {
                        $nextErrors[$x - 1] += $error * (3/32);
                    }
                    $nextErrors[$x] += $error * (5/32);
                    if ($x + 1 < $width) {
                        $nextErrors[$x + 1] += $error * (1/32);
                    }
                }
            } else {
                // Distribute error using standard Floyd-Steinberg coefficients
                // Current pixel: 0 (already processed)
                // Right pixel: 7/16
                // Below left: 3/16, Below: 5/16, Below right: 1/16
                
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * (7/16);
                }
                
                if ($y + 1 < $height) {
                    if ($x > 0) {
                        $nextErrors[$x - 1] += $error * (3/16);
                    }
                    $nextErrors[$x] += $error * (5/16);
                    if ($x + 1 < $width) {
                        $nextErrors[$x + 1] += $error * (1/16);
                    }
                }
            }
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
        }
        
        // Move to next row
        $errors = $nextErrors;
    }
}

function atkinsonDither($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffer
    $errors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        $nextErrors = array_fill(0, $width, 0);
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $errors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            // Distribute error using Atkinson coefficients (1/8 for each neighbor)
            // Reduce bleeding if requested by using 1/16 instead of 1/8
            $errorFraction = $rb ? (1/16) : (1/8);
            
            if ($x + 1 < $width) {
                $nextErrors[$x + 1] += $error * $errorFraction;
            }
            if ($x + 2 < $width) {
                $nextErrors[$x + 2] += $error * $errorFraction;
            }
            if ($y + 1 < $height) {
                if ($x > 0) {
                    $nextErrors[$x - 1] += $error * $errorFraction;
                }
                $nextErrors[$x] += $error * $errorFraction;
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * $errorFraction;
                }
            }
            if ($y + 2 < $height) {
                $nextErrors[$x] += $error * $errorFraction;
            }
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
        }
        
        // Move to next row
        $errors = $nextErrors;
    }
}

function jarvisDither($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffer
    $errors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        $nextErrors = array_fill(0, $width, 0);
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $errors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            if ($rb) {
                // Distribute error using reduced Jarvis coefficients (half the original values)
                // Row below: 7/48, 5/48, 3/48, 5/48, 7/48
                // Two rows below: 3/48, 5/48, 7/48, 5/48, 3/48
                if ($x - 2 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 2] += $error * (7/96);
                }
                if ($x - 1 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 1] += $error * (5/96);
                }
                if ($y + 1 < $height) {
                    $nextErrors[$x] += $error * (3/96);
                }
                if ($x + 1 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 1] += $error * (5/96);
                }
                if ($x + 2 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 2] += $error * (7/96);
                }
                if ($x - 2 >= 0 && $y + 2 < $height) {
                    $nextErrors[$x - 2] += $error * (3/96);
                }
                if ($x - 1 >= 0 && $y + 2 < $height) {
                    $nextErrors[$x - 1] += $error * (5/96);
                }
                if ($y + 2 < $height) {
                    $nextErrors[$x] += $error * (7/96);
                }
                if ($x + 1 < $width && $y + 2 < $height) {
                    $nextErrors[$x + 1] += $error * (5/96);
                }
                if ($x + 2 < $width && $y + 2 < $height) {
                    $nextErrors[$x + 2] += $error * (3/96);
                }
            } else {
                // Distribute error using standard Jarvis coefficients
                // Row below: 7/48, 5/48, 3/48, 5/48, 7/48
                // Two rows below: 3/48, 5/48, 7/48, 5/48, 3/48
                if ($x - 2 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 2] += $error * (7/48);
                }
                if ($x - 1 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 1] += $error * (5/48);
                }
                if ($y + 1 < $height) {
                    $nextErrors[$x] += $error * (3/48);
                }
                if ($x + 1 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 1] += $error * (5/48);
                }
                if ($x + 2 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 2] += $error * (7/48);
                }
                if ($x - 2 >= 0 && $y + 2 < $height) {
                    $nextErrors[$x - 2] += $error * (3/48);
                }
                if ($x - 1 >= 0 && $y + 2 < $height) {
                    $nextErrors[$x - 1] += $error * (5/48);
                }
                if ($y + 2 < $height) {
                    $nextErrors[$x] += $error * (7/48);
                }
                if ($x + 1 < $width && $y + 2 < $height) {
                    $nextErrors[$x + 1] += $error * (5/48);
                }
                if ($x + 2 < $width && $y + 2 < $height) {
                    $nextErrors[$x + 2] += $error * (3/48);
                }
            }
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
        }
        
        // Move to next row
        $errors = $nextErrors;
    }
}

function stuckiDither($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffer
    $errors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        $nextErrors = array_fill(0, $width, 0);
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $errors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            if ($rb) {
                // Distribute error using reduced Stucki coefficients (half the original values)
                // Row below: 8/42, 4/42, 2/42, 4/42, 8/42
                // Two rows below: 2/42, 4/42, 8/42, 4/42, 2/42
                if ($x - 2 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 2] += $error * (8/84);
                }
                if ($x - 1 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 1] += $error * (4/84);
                }
                if ($y + 1 < $height) {
                    $nextErrors[$x] += $error * (2/84);
                }
                if ($x + 1 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 1] += $error * (4/84);
                }
                if ($x + 2 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 2] += $error * (8/84);
                }
                if ($x - 2 >= 0 && $y + 2 < $height) {
                    $nextErrors[$x - 2] += $error * (2/84);
                }
                if ($x - 1 >= 0 && $y + 2 < $height) {
                    $nextErrors[$x - 1] += $error * (4/84);
                }
                if ($y + 2 < $height) {
                    $nextErrors[$x] += $error * (8/84);
                }
                if ($x + 1 < $width && $y + 2 < $height) {
                    $nextErrors[$x + 1] += $error * (4/84);
                }
                if ($x + 2 < $width && $y + 2 < $height) {
                    $nextErrors[$x + 2] += $error * (2/84);
                }
            } else {
                // Distribute error using standard Stucki coefficients
                // Row below: 8/42, 4/42, 2/42, 4/42, 8/42
                // Two rows below: 2/42, 4/42, 8/42, 4/42, 2/42
                if ($x - 2 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 2] += $error * (8/42);
                }
                if ($x - 1 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 1] += $error * (4/42);
                }
                if ($y + 1 < $height) {
                    $nextErrors[$x] += $error * (2/42);
                }
                if ($x + 1 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 1] += $error * (4/42);
                }
                if ($x + 2 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 2] += $error * (8/42);
                }
                if ($x - 2 >= 0 && $y + 2 < $height) {
                    $nextErrors[$x - 2] += $error * (2/42);
                }
                if ($x - 1 >= 0 && $y + 2 < $height) {
                    $nextErrors[$x - 1] += $error * (4/42);
                }
                if ($y + 2 < $height) {
                    $nextErrors[$x] += $error * (8/42);
                }
                if ($x + 1 < $width && $y + 2 < $height) {
                    $nextErrors[$x + 1] += $error * (4/42);
                }
                if ($x + 2 < $width && $y + 2 < $height) {
                    $nextErrors[$x + 2] += $error * (2/42);
                }
            }
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
        }
        
        // Move to next row
        $errors = $nextErrors;
    }
}

function bayer2x2Dither($image, $levels) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Bayer 2x2 matrix (values scaled to 0-255 range)
    $bayerMatrix = [
        [0, 128],
        [192, 64]
    ];
    
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Get threshold from Bayer matrix
            $threshold = $bayerMatrix[$y % 2][$x % 2];
            
            // Apply threshold
            $adjustedGray = $gray + ($threshold - 128) / 255 * $step;
            
            // Quantize to specified number of levels
            $quantized = round(round($adjustedGray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
        }
    }
}

function burkesDither($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffer
    $errors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        $nextErrors = array_fill(0, $width, 0);
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $errors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            if ($rb) {
                // Distribute error using reduced Burkes coefficients (half the original values)
                // Row below: 8/32, 4/32, 2/32, 4/32, 8/32
                if ($x - 2 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 2] += $error * (8/64);
                }
                if ($x - 1 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 1] += $error * (4/64);
                }
                if ($y + 1 < $height) {
                    $nextErrors[$x] += $error * (2/64);
                }
                if ($x + 1 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 1] += $error * (4/64);
                }
                if ($x + 2 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 2] += $error * (8/64);
                }
            } else {
                // Distribute error using standard Burkes coefficients
                // Row below: 8/32, 4/32, 2/32, 4/32, 8/32
                if ($x - 2 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 2] += $error * (8/32);
                }
                if ($x - 1 >= 0 && $y + 1 < $height) {
                    $nextErrors[$x - 1] += $error * (4/32);
                }
                if ($y + 1 < $height) {
                    $nextErrors[$x] += $error * (2/32);
                }
                if ($x + 1 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 1] += $error * (4/32);
                }
                if ($x + 2 < $width && $y + 1 < $height) {
                    $nextErrors[$x + 2] += $error * (8/32);
                }
            }
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
        }
        
        // Move to next row
        $errors = $nextErrors;
    }
}

// Add this before the try block to capture output
ob_start();

try {
    // Check if URL parameter is provided
    if ($imageUrl) {
        // Validate URL
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL provided');
        }
        
        // Fetch image from URL
        $imageData = file_get_contents($imageUrl);
        if ($imageData === false) {
            throw new Exception('Failed to fetch image from URL');
        }
    } else {
        // Check if this is a POST request with image data
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Check if image file was uploaded
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                // Get image data from uploaded file
                $imageData = file_get_contents($_FILES['image']['tmp_name']);
                if ($imageData === false) {
                    throw new Exception('Failed to read uploaded image');
                }
            } else {
                // Try to get image data from POST body
                $imageData = file_get_contents('php://input');
                if (empty($imageData)) {
                    throw new Exception('No image data provided');
                }
            }
        } else {
            // Fetch random image from specified collection
            $imageData = fetchRandomImage($col);
        }
    }
    
    // Process the image
    $processedImage = processImage($imageData, $levels, $targetWidth, $targetHeight, $dth, $rb);
    
    // Save the processed image to a temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'ditherbox_');
    switch ($fmt) {
        case 'jpg':
        case 'jpeg':
            imagejpeg($processedImage, $tempFile, 90);
            break;
        case 'gif':
            imagegif($processedImage, $tempFile);
            break;
        case 'ppm':
            // For PPM, we'll convert to PNG for web display
            imagepng($processedImage, $tempFile);
            break;
        case 'pbm':
            // For PBM, we'll convert to PNG for web display
            imagepng($processedImage, $tempFile);
            break;
        case 'png':
        default:
            imagepng($processedImage, $tempFile);
            break;
    }
    
    // Clean up
    imagedestroy($processedImage);
    
    // Get the image data for embedding
    $imageData = file_get_contents($tempFile);
    unlink($tempFile);
    
    // Get the MIME type for the image
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_buffer($finfo, $imageData);
    finfo_close($finfo);
    
    // Convert image data to base64 for embedding
    $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
    
    // Get the form output
    $formOutput = ob_get_clean();
    
    // Display the result page with embedded image
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DitherBox - Result</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
        <style>
            .container {
                max-width: 800px;
                margin: 2rem auto;
                padding: 0 1rem;
            }
            .result-image {
                width: 100%;
                max-width: <?php echo $targetWidth; ?>px;
                height: auto;
                image-rendering: pixelated;
            }
            .note {
                font-size: 0.9rem;
                color: var(--pico-muted-color);
            }
        </style>
    </head>
    <body>
        <main class="container">
            <h1>DitherBox Result</h1>
            
            <div>
                <img src="<?php echo $base64Image; ?>" alt="Processed Image" class="result-image">
            </div>
            
            <div>
                <h2>Image Details</h2>
                <ul>
                    <li>Format: <?php echo strtoupper($fmt); ?></li>
                    <li>Resolution: <?php echo $targetWidth; ?>x<?php echo $targetHeight; ?></li>
                    <li>Grayscale Bits: <?php echo $bits; ?></li>
                    <li>Dithering Method: <?php echo $dth; ?></li>
                    <li>Reduce Bleeding: <?php echo $rb ? 'Yes' : 'No'; ?></li>
                </ul>
            </div>
            
            <div>
                <h2>Process Another Image</h2>
                <?php echo $formOutput; ?>
            </div>
        </main>
    </body>
    </html>
    <?php
} catch (Exception $e) {
    // Get the form output
    $formOutput = ob_get_clean();
    
    // Handle errors
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DitherBox - Error</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
        <style>
            .container {
                max-width: 800px;
                margin: 2rem auto;
                padding: 0 1rem;
            }
            .error {
                color: red;
                padding: 1rem;
                border: 1px solid red;
                border-radius: 4px;
                background-color: #ffe6e6;
            }
        </style>
    </head>
    <body>
        <main class="container">
            <h1>DitherBox Error</h1>
            
            <div class="error">
                Error: <?php echo $e->getMessage(); ?>
            </div>
            
            <div>
                <h2>Try Again</h2>
                <?php echo $formOutput; ?>
            </div>
        </main>
    </body>
    </html>
    <?php
}
?>
