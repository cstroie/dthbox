<?php
// Fetch and process art images from specified collection
// Then crop and scale it to 296x128 format and return in specified format

// Get collection from query parameter, default to apod
$collection = isset($_GET['collection']) ? strtolower($_GET['collection']) : 'apod';
$allowedCollections = ['apod', 'wikiart'];
if (!in_array($collection, $allowedCollections)) {
    $collection = 'apod'; // Default to apod if invalid collection
}

// Get format from query parameter, default to png
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'png';
$allowedFormats = ['png', 'jpg', 'jpeg', 'ppm', 'pbm', 'gif'];
if (!in_array($format, $allowedFormats)) {
    $format = 'png'; // Default to png if invalid format
}

// Get grayscale levels from query parameter, default to 256 (full grayscale)
$levels = isset($_GET['levels']) ? intval($_GET['levels']) : 256;
// Clamp levels between 2 and 256
$levels = max(2, min(256, $levels));

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

function fetchRandomWikiartImage() {
    // For demonstration, we'll use a placeholder
    // In a real implementation, this would connect to WikiArt's API
    // or scrape their website to get random artwork images
    
    // This is a placeholder implementation that returns a sample image
    // A real implementation would fetch from https://www.wikiart.org/
    throw new Exception('WikiArt collection not yet implemented');
    
    // Example of what a real implementation might look like:
    /*
    $apiUrl = 'https://www.wikiart.org/en/api/2/Paintings';
    $apiContent = file_get_contents($apiUrl);
    
    if ($apiContent === false) {
        throw new Exception('Failed to fetch WikiArt API');
    }
    
    $data = json_decode($apiContent, true);
    
    if (!$data || !isset($data['data'])) {
        throw new Exception('Failed to parse WikiArt API response');
    }
    
    // Select a random painting
    $paintings = $data['data'];
    $randomPainting = $paintings[array_rand($paintings)];
    
    // Fetch the image
    $imageUrl = $randomPainting['image'];
    $imageData = file_get_contents($imageUrl);
    
    if ($imageData === false) {
        throw new Exception('Failed to fetch image from WikiArt');
    }
    
    return $imageData;
    */
}

function fetchRandomImage($collection) {
    switch ($collection) {
        case 'wikiart':
            return fetchRandomWikiartImage();
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
    
    // Apply quantization to reduce grayscale levels if needed
    if ($levels < 256) {
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

try {
    // Fetch random image from specified collection
    $imageData = fetchRandomImage($collection);
    
    // Process the image
    $processedImage = processImage($imageData, $levels);
    
    // Output in specified format
    switch ($format) {
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
            echo "P4\n296 128\n";
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
