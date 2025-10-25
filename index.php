<?php
// Fetch and process art images from specified collection
// Then crop and scale it to 296x128 format and return in specified format

// Check if this is a POST request with image data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get parameters from POST data or use defaults
    $fmt = isset($_POST['fmt']) ? strtolower($_POST['fmt']) : 'png';
    $bits = isset($_POST['bits']) ? intval($_POST['bits']) : 1;
    $res = isset($_POST['res']) ? $_POST['res'] : '296x128';
    $ditherMethod = isset($_POST['ditherMethod']) ? $_POST['ditherMethod'] : 'floyd-steinberg';
    $reduceBleeding = isset($_POST['reduceBleeding']) ? (bool)$_POST['reduceBleeding'] : true;
        
    // Validate and parse resolution
    if (preg_match('/^(\d+)x(\d+)$/', $res, $matches)) {
        $targetWidth = intval($matches[1]);
        $targetHeight = intval($matches[2]);
        // Ensure reasonable limits to prevent abuse
        $targetWidth = max(1, min(2000, $targetWidth));
        $targetHeight = max(1, min(2000, $targetHeight));
    } else {
        // Default to 296x128 if invalid format
        $targetWidth = 296;
        $targetHeight = 128;
    }
        
    // Clamp bits between 1 and 8
    $bits = max(1, min(8, $bits));
    // Convert bits to levels
    $levels = pow(2, $bits);
        
    // Get allowed formats
    $allowedFormats = ['png', 'jpg', 'jpeg', 'ppm', 'pbm', 'gif'];
    if (!in_array($fmt, $allowedFormats)) {
        $fmt = 'png'; // Default to png if invalid format
    }
        
    // Check for URL parameter in POST data
    $imageUrl = isset($_POST['url']) ? $_POST['url'] : null;
} else {
    // Check for URL parameter in GET data
    $imageUrl = isset($_GET['url']) ? $_GET['url'] : null;
        
    // Get collection from query parameter, default to random selection
    $col = isset($_GET['col']) ? strtolower($_GET['col']) : 'any';
    $allowedCollections = ['apod', 'tic', 'jux', 'veri'];

    // If collection is 'any' or not specified, choose randomly from available collections
    // But only if no URL is provided
    if (!$imageUrl && ($col === 'any' || !in_array($col, $allowedCollections))) {
        $col = $allowedCollections[array_rand($allowedCollections)];
    }

    // Get format from query parameter, default to png
    $fmt = isset($_GET['fmt']) ? strtolower($_GET['fmt']) : 'png';
    $allowedFormats = ['png', 'jpg', 'jpeg', 'ppm', 'pbm', 'gif'];
    if (!in_array($fmt, $allowedFormats)) {
        $fmt = 'png'; // Default to png if invalid format
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
    } else {
        // Default to 296x128 if invalid format
        $targetWidth = 296;
        $targetHeight = 128;
    }
        
    // Get dithering parameters
    $ditherMethod = isset($_GET['ditherMethod']) ? $_GET['ditherMethod'] : 'floyd-steinberg';
    $reduceBleeding = isset($_GET['reduceBleeding']) ? (bool)$_GET['reduceBleeding'] : true;
        
    // If no 'col' or 'url' are provided, show the upload form
    if (!isset($_GET['col']) && !isset($_GET['url'])) {
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
        </style>
    </head>
    <body>
        <main class="container">
            <h1>DitherBox</h1>
            
            <div class="tab">
                <button class="tablinks active" onclick="openTab(event, 'url')">Via URL</button>
                <button class="tablinks" onclick="openTab(event, 'file')">Upload File</button>
                <button class="tablinks" onclick="openTab(event, 'collection')">Random Collection</button>
            </div>
            
            <div id="url" class="tabcontent" style="display:block">
                <form method="GET" action="">
                    <div>
                        <label for="url_input">Image URL:</label>
                        <input type="url" id="url_input" name="url" placeholder="https://example.com/image.jpg">
                    </div>
                    <div>
                        <label for="fmt_url">Output Format:</label>
                        <select id="fmt_url" name="fmt">
                            <option value="png">PNG</option>
                            <option value="jpg">JPG</option>
                            <option value="ppm">PPM</option>
                            <option value="pbm">PBM</option>
                            <option value="gif">GIF</option>
                        </select>
                    </div>
                    <div>
                        <label for="bits_url">Grayscale Bits: <span id="bits_url_value">1</span></label>
                        <input type="range" id="bits_url" name="bits" min="1" max="8" value="1" oninput="document.getElementById('bits_url_value').textContent = this.value">
                    </div>
                    <div>
                        <label for="ditherMethod_url">Dithering Method:</label>
                        <select id="ditherMethod_url" name="ditherMethod">
                            <option value="none">None</option>
                            <option value="floyd-steinberg" selected>Floyd-Steinberg</option>
                            <option value="atkinson">Atkinson</option>
                            <option value="jarvis">Jarvis, Judice & Ninke</option>
                            <option value="stucki">Stucki</option>
                            <option value="burkes">Burkes</option>
                        </select>
                    </div>
                    <div>
                        <label>
                            <input type="checkbox" id="reduceBleeding_url" name="reduceBleeding" value="1" checked>
                            Reduce Color Bleeding
                        </label>
                    </div>
                    <div>
                        <label for="res_url">Resolution (WxH):</label>
                        <input type="text" id="res_url" name="res" value="296x128" placeholder="296x128">
                    </div>
                    <input type="submit" value="Process Image from URL">
                </form>
            </div>
            
            <div id="file" class="tabcontent" style="display:none">
                <form method="POST" action="" enctype="multipart/form-data">
                    <div>
                        <label for="image">Select Image File:</label>
                        <input type="file" id="image" name="image" accept="image/*">
                    </div>
                    <div>
                        <label for="fmt_file">Output Format:</label>
                        <select id="fmt_file" name="fmt">
                            <option value="png">PNG</option>
                            <option value="jpg">JPG</option>
                            <option value="ppm">PPM</option>
                            <option value="pbm">PBM</option>
                            <option value="gif">GIF</option>
                        </select>
                    </div>
                    <div>
                        <label for="bits_file">Grayscale Bits: <span id="bits_file_value">1</span></label>
                        <input type="range" id="bits_file" name="bits" min="1" max="8" value="1" oninput="document.getElementById('bits_file_value').textContent = this.value">
                    </div>
                    <div>
                        <label for="ditherMethod_file">Dithering Method:</label>
                        <select id="ditherMethod_file" name="ditherMethod">
                            <option value="none">None</option>
                            <option value="floyd-steinberg" selected>Floyd-Steinberg</option>
                            <option value="atkinson">Atkinson</option>
                            <option value="jarvis">Jarvis, Judice & Ninke</option>
                            <option value="stucki">Stucki</option>
                            <option value="burkes">Burkes</option>
                        </select>
                    </div>
                    <div>
                        <label>
                            <input type="checkbox" id="reduceBleeding_file" name="reduceBleeding" value="1" checked>
                            Reduce Color Bleeding
                        </label>
                    </div>
                    <div>
                        <label for="res_file">Resolution (WxH):</label>
                        <input type="text" id="res_file" name="res" value="296x128" placeholder="296x128">
                    </div>
                    <input type="submit" value="Process Uploaded Image">
                </form>
            </div>
            
            <div id="collection" class="tabcontent" style="display:none">
                <form method="GET" action="">
                    <div>
                        <label for="col">Collection:</label>
                        <select id="col" name="col">
                            <option value="apod">Astronomy Picture of the Day</option>
                            <option value="tic">This Is Colossal</option>
                            <option value="jux">Juxtapoz</option>
                            <option value="veri">Veri Artem</option>
                            <option value="any">Random Collection</option>
                        </select>
                    </div>
                    <div>
                        <label for="fmt_col">Output Format:</label>
                        <select id="fmt_col" name="fmt">
                            <option value="png">PNG</option>
                            <option value="jpg">JPG</option>
                            <option value="ppm">PPM</option>
                            <option value="pbm">PBM</option>
                            <option value="gif">GIF</option>
                        </select>
                    </div>
                    <div>
                        <label for="bits_col">Grayscale Bits: <span id="bits_col_value">1</span></label>
                        <input type="range" id="bits_col" name="bits" min="1" max="8" value="1" oninput="document.getElementById('bits_col_value').textContent = this.value">
                    </div>
                    <div>
                        <label for="ditherMethod_col">Dithering Method:</label>
                        <select id="ditherMethod_col" name="ditherMethod">
                            <option value="none">None</option>
                            <option value="floyd-steinberg" selected>Floyd-Steinberg</option>
                            <option value="atkinson">Atkinson</option>
                            <option value="jarvis">Jarvis, Judice & Ninke</option>
                            <option value="stucki">Stucki</option>
                            <option value="burkes">Burkes</option>
                        </select>
                    </div>
                    <div>
                        <label>
                            <input type="checkbox" id="reduceBleeding_col" name="reduceBleeding" value="1" checked>
                            Reduce Color Bleeding
                        </label>
                    </div>
                    <div>
                        <label for="res_col">Resolution (WxH):</label>
                        <input type="text" id="res_col" name="res" value="296x128" placeholder="296x128">
                    </div>
                    <input type="submit" value="Process Random Image">
                </form>
            </div>
            
            <p class="note">Note: DitherBox processes images with customizable dithering and grayscale levels.</p>
        </main>
        
        <script>
            function openTab(evt, tabName) {
                var i, tabcontent, tablinks;
                tabcontent = document.getElementsByClassName("tabcontent");
                for (i = 0; i < tabcontent.length; i++) {
                    tabcontent[i].style.display = "none";
                }
                tablinks = document.getElementsByClassName("tablinks");
                for (i = 0; i < tablinks.length; i++) {
                    tablinks[i].className = tablinks[i].className.replace(" active", "");
                }
                document.getElementById(tabName).style.display = "block";
                evt.currentTarget.className += " active";
            }
        </script>
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

function processImage($imageData, $levels, $targetWidth, $targetHeight) {
    // Create image from data
    $srcImage = imagecreatefromstring($imageData);
    
    if ($srcImage === false) {
        throw new Exception('Failed to create image from data');
    }
    
    // Get original dimensions
    $srcWidth = imagesx($srcImage);
    $srcHeight = imagesy($srcImage);
    
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
    if ($levels < 256 && $ditherMethod !== 'none') {
        switch ($ditherMethod) {
            case 'floyd-steinberg':
                floydSteinbergDither($dstImage, $levels, $reduceBleeding);
                break;
            case 'atkinson':
                atkinsonDither($dstImage, $levels, $reduceBleeding);
                break;
            case 'jarvis':
                jarvisDither($dstImage, $levels, $reduceBleeding);
                break;
            case 'stucki':
                stuckiDither($dstImage, $levels, $reduceBleeding);
                break;
            case 'burkes':
                burkesDither($dstImage, $levels, $reduceBleeding);
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

function floydSteinbergDither($image, $levels, $reduceBleeding = true) {
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
            
            if ($reduceBleeding) {
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

function atkinsonDither($image, $levels, $reduceBleeding = true) {
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
            $errorFraction = $reduceBleeding ? (1/16) : (1/8);
            
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

function jarvisDither($image, $levels, $reduceBleeding = true) {
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
            
            if ($reduceBleeding) {
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

function stuckiDither($image, $levels, $reduceBleeding = true) {
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
            
            if ($reduceBleeding) {
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

function burkesDither($image, $levels, $reduceBleeding = true) {
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
            
            if ($reduceBleeding) {
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
    $processedImage = processImage($imageData, $levels, $targetWidth, $targetHeight);
    
    // Output in specified format
    switch ($fmt) {
        case 'jpg':
        case 'jpeg':
            header('Content-Type: image/jpeg');
            imagejpeg($processedImage, null, 90);
            break;
        case 'gif':
            header('Content-Type: image/gif');
            imagegif($processedImage);
            break;
        case 'ppm':
            header('Content-Type: image/x-portable-pixmap');
            // PPM format: P6 width height max_color_value binary_data
            echo "P6\n{$targetWidth} {$targetHeight}\n255\n";
            for ($y = 0; $y < $targetHeight; $y++) {
                for ($x = 0; $x < $targetWidth; $x++) {
                    $rgb = imagecolorat($processedImage, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    echo chr($r) . chr($g) . chr($b);
                }
                echo "\n";
            }
            break;
        case 'pbm':
            header('Content-Type: image/x-portable-bitmap');
            // PBM format: P4 width height binary_data
            echo "P4\n";
            // Add comment with collection name
            echo "# " . strtoupper($col) . "\n";
            echo "{$targetWidth} {$targetHeight}\n";
            for ($y = 0; $y < $targetHeight; $y++) {
                $byte = 0;
                $bitCount = 0;
                for ($x = 0; $x < $targetWidth; $x++) {
                    $rgb = imagecolorat($processedImage, $x, $y);
                    $gray = ($rgb >> 16) & 0xFF; // Get grayscale value
                    // Threshold at 128 for binary conversion
                    $bit = ($gray < 128) ? 1 : 0;
                    $byte = ($byte << 1) | $bit;
                    $bitCount++;
                    
                    if ($bitCount == 8) {
                        echo chr($byte);
                        $byte = 0;
                        $bitCount = 0;
                    }
                }
                // Handle remaining bits in the row
                if ($bitCount > 0) {
                    $byte <<= (8 - $bitCount);
                    echo chr($byte);
                }
            }
            break;
        case 'png':
        default:
            header('Content-Type: image/png');
            imagepng($processedImage);
            break;
    }
    
    // Clean up
    imagedestroy($processedImage);
} catch (Exception $e) {
    // Handle errors
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
?>
