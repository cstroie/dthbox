<?php
// Fetch and process art images from specified collection
// Then crop and scale it to 296x128 format and return in specified format

// Get collection from query parameter, default to random selection
$col = isset($_GET['col']) ? strtolower($_GET['col']) : 'any';
$allowedCollections = ['apod', 'tic', 'jux', 'veri'];

// If collection is 'any' or not specified, choose randomly from available collections
if ($col === 'any' || !in_array($col, $allowedCollections)) {
    $col = $allowedCollections[array_rand($allowedCollections)];
}

// Get format from query parameter, default to png
$fmt = isset($_GET['fmt']) ? strtolower($_GET['fmt']) : 'png';
$allowedFormats = ['png', 'jpg', 'jpeg', 'ppm', 'pbm', 'gif'];
if (!in_array($fmt, $allowedFormats)) {
    $fmt = 'png'; // Default to png if invalid format
}

// Get grayscale levels from query parameter, default to 256 (full grayscale)
$lvl = isset($_GET['lvl']) ? intval($_GET['lvl']) : 256;
// Clamp levels between 2 and 256
$lvl = max(2, min(256, $lvl));

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

function processImage($imageData, $levels) {
    // Create image from data
    $srcImage = imagecreatefromstring($imageData);
    
    if ($srcImage === false) {
        throw new Exception('Failed to create image from data');
    }
    
    // Get original dimensions
    $srcWidth = imagesx($srcImage);
    $srcHeight = imagesy($srcImage);
    
    // Calculate crop dimensions to maintain aspect ratio
    $targetWidth = 296;
    $targetHeight = 128;
    
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
    
    // Apply Floyd-Steinberg dithering for low levels, otherwise simple quantization
    if ($levels < 256) {
        if ($levels < 16) {
            // Use Floyd-Steinberg dithering for very low color levels
            floydSteinbergDither($dstImage, $levels);
        } else {
            // Simple quantization for higher levels
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
    }
    
    // Clean up source image
    imagedestroy($srcImage);
    
    return $dstImage;
}

function floydSteinbergDither($image, $levels) {
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
            
            // Distribute error using Floyd-Steinberg coefficients
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
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
        }
        
        // Move to next row
        $errors = $nextErrors;
    }
}

try {
    // Fetch random image from specified collection
    $imageData = fetchRandomImage($col);
    
    // Process the image
    $processedImage = processImage($imageData, $lvl);
    
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
            echo "P6\n296 128\n255\n";
            for ($y = 0; $y < 128; $y++) {
                for ($x = 0; $x < 296; $x++) {
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
            echo "296 128\n";
            for ($y = 0; $y < 128; $y++) {
                $byte = 0;
                $bitCount = 0;
                for ($x = 0; $x < 296; $x++) {
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
