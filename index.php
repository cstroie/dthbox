<?php
/*
 * DitherBox - A PHP-based image dithering tool
 * Copyright (C) 2025 Costin Stroie <costinstroie@eridu.eu.org>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Project: DitherBox
 * Repository: https://github.com/cstroie/dthbox
 */

// Fetch and process art images from specified collection
// Then crop and scale it to 296x128 format and return in specified format

// Define available collections, formats, and default resolution globally
global $collections;
$collections = [
    'apod' => [
        'id' => 'apod',
        'name' => 'Astronomy Picture of the Day',
        'url' => 'https://apod.com/feed.rss'
    ],
    'tic' => [
        'id' => 'tic',
        'name' => 'This Is Colossal',
        'url' => 'https://www.thisiscolossal.com/feed/'
    ],
    'jux' => [
        'id' => 'jux',
        'name' => 'Juxtapoz',
        'url' => 'https://www.juxtapoz.com/news/?format=feed&type=rss'
    ],
    'veri' => [
        'id' => 'veri',
        'name' => 'Veri Artem',
        'url' => 'https://veriartem.com/feed/'
    ],
    'cat' => [
        'id' => 'cat',
        'name' => 'Catster',
        'url' => 'https://www.catster.com/feed/'
    ]
];
global $formats;
$formats = ['png', 'jpg', 'jpeg', 'ppm', 'pbm', 'gif'];
global $defRes;
$defRes = '400x300';
global $dthMethods;
$dthMethods = [
    'none' => 'None',
    'fs' => 'Floyd-Steinberg',
    'ak' => 'Atkinson',
    'jv' => 'Jarvis, Judice & Ninke',
    'sk' => 'Stucki',
    'bk' => 'Burkes',
    'by' => 'Bayer 2x2'
];

// Check if this is a POST request with image data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get parameters from POST data or use defaults
    $fmt = isset($_POST['fmt']) ? strtolower($_POST['fmt']) : 'png';
    $bits = isset($_POST['bits']) ? intval($_POST['bits']) : 1;
    $res = isset($_POST['res']) ? $_POST['res'] : $defRes;
    $dth = isset($_POST['dth']) ? $_POST['dth'] : 'fs';
    $rb = isset($_POST['rb']) ? (bool)$_POST['rb'] : true;
        
    // Validate and parse resolution
    if (preg_match('/^(\d+)x(\d+)$/', $res, $matches)) {
        $tgtWidth = intval($matches[1]);
        $tgtHeight = intval($matches[2]);
        // Ensure reasonable limits to prevent abuse
        $tgtWidth = max(1, min(2000, $tgtWidth));
        $tgtHeight = max(1, min(2000, $tgtHeight));
    } else if (is_numeric($res)) {
        // If res is a single number, treat it as maximum size
        $maxSize = max(1, min(2000, intval($res)));
        // We'll determine actual dimensions after loading the image
        $tgtWidth = $maxSize;
        $tgtHeight = $maxSize;
        $useMaxSize = true;
    } else {
        // Default to global default resolution if invalid format
        global $defRes;
        if (preg_match('/^(\d+)x(\d+)$/', $defRes, $matches)) {
            $tgtWidth = intval($matches[1]);
            $tgtHeight = intval($matches[2]);
        } else {
            $tgtWidth = 400;
            $tgtHeight = 300;
        }
        // Ensure reasonable limits to prevent abuse
        $tgtWidth = max(1, min(2000, $tgtWidth));
        $tgtHeight = max(1, min(2000, $tgtHeight));
    }
        
    // Clamp bits between 1 and 8
    $bits = max(1, min(8, $bits));
    // Convert bits to levels
    $levels = pow(2, $bits);
        
    // Get allowed formats
    global $formats;
    if (!in_array($fmt, $formats)) {
        // Default to png if invalid format
        $fmt = 'png';
    }
        
    // Check for URL parameter in POST data
    $imageUrl = isset($_POST['url']) ? $_POST['url'] : null;
    
    // Get collection from POST data if available
    $col = isset($_POST['col']) ? strtolower($_POST['col']) : 'any';
    global $collections;

    // If collection is 'any' or not specified, choose randomly from available collections
    // But only if no URL is provided
    if (!$imageUrl && ($col === 'any' || !isset($collections[$col]))) {
        $colKeys = array_keys($collections);
        $col = $colKeys[array_rand($colKeys)];
    }
} else {
    // Check for URL parameter in GET data
    $imageUrl = isset($_GET['url']) ? $_GET['url'] : null;
        
    // Get collection from query parameter, default to random selection
    $col = isset($_GET['col']) ? strtolower($_GET['col']) : 'any';
    global $collections;

    // If collection is 'any' or not specified, choose randomly from available collections
    // But only if no URL is provided
    if (!$imageUrl && ($col === 'any' || !isset($collections[$col]))) {
        $colKeys = array_keys($collections);
        $col = $colKeys[array_rand($colKeys)];
    }

    // Get format from query parameter, default to png
    $fmt = isset($_GET['fmt']) ? strtolower($_GET['fmt']) : 'png';
    global $formats;
    if (!in_array($fmt, $formats)) {
        // Default to png if invalid format
        $fmt = 'png';
    }

    // Get grayscale levels from query parameter, default to 2 (1 bit)
    $levels = isset($_GET['lvl']) ? intval($_GET['lvl']) : 0;
    if ($levels > 0) {
        // If levels specified, clamp between 2 and 256
        $levels = max(2, min(256, $levels));
    } else {
        // If no levels specified, use bits parameter
        $bits = isset($_GET['bits']) ? intval($_GET['bits']) : 1;
        // Clamp bits between 1 and 8
        $bits = max(1, min(8, $bits));
        // Convert bits to levels
        $levels = pow(2, $bits);
    }

    // Get resolution from query parameter, default to 400x300
    $res = isset($_GET['res']) ? $_GET['res'] : '400x300';
    // Validate and parse resolution
    if (preg_match('/^(\d+)x(\d+)$/', $res, $matches)) {
        $tgtWidth = intval($matches[1]);
        $tgtHeight = intval($matches[2]);
        // Ensure reasonable limits to prevent abuse
        $tgtWidth = max(1, min(2000, $tgtWidth));
        $tgtHeight = max(1, min(2000, $tgtHeight));
    } else if (is_numeric($res)) {
        // If res is a single number, treat it as maximum size
        $maxSize = max(1, min(2000, intval($res)));
        // We'll determine actual dimensions after loading the image
        $tgtWidth = $maxSize;
        $tgtHeight = $maxSize;
        $useMaxSize = true;
    } else {
        // Default to global default resolution if invalid format
        global $defRes;
        if (preg_match('/^(\d+)x(\d+)$/', $defRes, $matches)) {
            $tgtWidth = intval($matches[1]);
            $tgtHeight = intval($matches[2]);
        } else {
            $tgtWidth = 296;
            $tgtHeight = 128;
        }
        // Ensure reasonable limits to prevent abuse
        $tgtWidth = max(1, min(2000, $tgtWidth));
        $tgtHeight = max(1, min(2000, $tgtHeight));
    }
        
    // Get dithering parameters
    $dth = isset($_GET['dth']) ? $_GET['dth'] : 'fs';
    $rb = isset($_GET['rb']) ? (bool)$_GET['rb'] : true;
}


/**
 * Fetch image URLs from an RSS feed
 * This function retrieves and parses an RSS feed to extract image URLs
 * It first looks for image enclosures, then falls back to parsing img tags in descriptions
 * 
 * @param string $rssUrl The URL of the RSS feed to fetch
 * @param string $siteName The name of the site (for error messages)
 * @return array Array of image URLs found in the RSS feed
 * @throws Exception If the RSS feed cannot be fetched or parsed, or no images are found
 */
function fetchImagesFromRss($rssUrl, $siteName) {
    // Fetch the RSS feed
    $rssContent = file_get_contents($rssUrl);
    
    if ($rssContent === false) {
        throw new Exception("Failed to fetch $siteName RSS feed");
    }
    
    // Parse the RSS feed
    $rss = simplexml_load_string($rssContent);
    
    if ($rss === false) {
        throw new Exception("Failed to parse $siteName RSS feed");
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
        throw new Exception("No JPG images found in $siteName RSS feed");
    }
    
    return $imageUrls;
}

/**
 * Fetch a random image from a collection
 * 
 * @param string $collectionId The collection ID to fetch from
 * @return string Image data as binary string
 * @throws Exception If image cannot be fetched or processed
 */
function fetchRandomImage($collectionId) {
    global $collections;
    
    if (!isset($collections[$collectionId])) {
        throw new Exception("Invalid collection: $collectionId");
    }
    
    $collection = $collections[$collectionId];
    $imageUrls = fetchImagesFromRss($collection['url'], $collection['name']);
    
    // Select a random image
    $randomImageUrl = $imageUrls[array_rand($imageUrls)];
    
    // Fetch the image
    $imageData = file_get_contents($randomImageUrl);
    
    if ($imageData === false) {
        throw new Exception('Failed to fetch image from ' . $collection['name']);
    }
    
    return $imageData;
}

/**
 * Process an image with resizing, cropping, and dithering
 * This function takes raw image data and applies the specified processing:
 * 1. Creates an image resource from the data
 * 2. Resizes and crops to target dimensions
 * 3. Converts to grayscale
 * 4. Applies dithering or quantization
 * 
 * @param string $imageData Raw image data as binary string
 * @param int $levels Number of grayscale levels (2^bits)
 * @param int $tgtWidth Target width in pixels
 * @param int $tgtHeight Target height in pixels
 * @param string $dth Dithering method ('none', 'fs', 'ak', 'jv', 'sk', 'bk', 'by')
 * @param bool $rb Reduce bleeding flag
 * @param bool $useMaxSize Whether to use maximum size constraint (default: false)
 * @return resource Processed image resource
 * @throws Exception If image processing fails
 */
function processImage($imageData, $levels, $tgtWidth, $tgtHeight, $dth, $rb, $useMaxSize = false) {
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
        $maxSize = $tgtWidth; // Both width and height are set to the same max value
        if ($srcWidth > $srcHeight) {
            // Landscape image
            $tgtWidth = $maxSize;
            $tgtHeight = intval($srcHeight * $maxSize / $srcWidth);
        } else {
            // Portrait or square image
            $tgtHeight = $maxSize;
            $tgtWidth = intval($srcWidth * $maxSize / $srcHeight);
        }
        // Ensure dimensions are at least 1
        $tgtWidth = max(1, $tgtWidth);
        $tgtHeight = max(1, $tgtHeight);
        
        // Set crop dimensions to full image (no cropping)
        $cropWidth = $srcWidth;
        $cropHeight = $srcHeight;
        $srcX = 0;
        $srcY = 0;
    } else {
        // Calculate crop dimensions to maintain aspect ratio
        $srcRatio = $srcWidth / $srcHeight;
        $targetRatio = $tgtWidth / $tgtHeight;
        
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
    $dstImage = imagecreatetruecolor($tgtWidth, $tgtHeight);
    
    // Resize and crop
    imagecopyresampled(
        $dstImage, $srcImage,
        0, 0, $srcX, $srcY,
        $tgtWidth, $tgtHeight,
        $cropWidth, $cropHeight
    );
    
    // Convert to grayscale
    imagefilter($dstImage, IMG_FILTER_GRAYSCALE);
    
    // Apply dithering based on selected method for low levels, otherwise simple quantization
    if ($levels < 256 && $dth !== 'none') {
        switch ($dth) {
            case 'fs':
                dthFloydSteinberg($dstImage, $levels, $rb);
                break;
            case 'ak':
                dthAtkinson($dstImage, $levels, $rb);
                break;
            case 'jv':
                dthJarvis($dstImage, $levels, $rb);
                break;
            case 'sk':
                dthStucki($dstImage, $levels, $rb);
                break;
            case 'bk':
                dthBurkes($dstImage, $levels, $rb);
                break;
            case 'by':
                bayer2x2Dither($dstImage, $levels);
                break;
            default:
                // Simple quantization for unknown methods
                dthNone($dstImage, $levels);
                break;
        }
    } else if ($levels < 256) {
        // Simple quantization when dithering is disabled
        dthNone($dstImage, $levels);
    }
    
    // Clean up source image
    imagedestroy($srcImage);
    
    return $dstImage;
}

/**
 * Apply Floyd-Steinberg dithering to an image
 * This function implements the Floyd-Steinberg error diffusion algorithm
 * with optional reduced bleeding mode
 * 
 * @param resource $image Image resource to process
 * @param int $levels Number of grayscale levels
 * @param bool $rb Reduce bleeding flag (default: true)
 * @return void Modifies the image resource directly
 */
function dthFloydSteinberg($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffer for current and next row
    $currentErrors = array_fill(0, $width, 0);
    $nextErrors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        // Reset next row errors
        for ($i = 0; $i < $width; $i++) {
            $nextErrors[$i] = 0;
        }
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $currentErrors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
            
            // Distribute error to neighboring pixels
            if ($rb) {
                // Reduced bleeding mode - use half the standard coefficients
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
                // Standard Floyd-Steinberg coefficients
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
        }
        
        // Swap error buffers
        $temp = $currentErrors;
        $currentErrors = $nextErrors;
        $nextErrors = $temp;
    }
}

/**
 * Apply Atkinson dithering to an image
 * This function implements the Atkinson error diffusion algorithm
 * with optional reduced bleeding mode
 * 
 * @param resource $image Image resource to process
 * @param int $levels Number of grayscale levels
 * @param bool $rb Reduce bleeding flag (default: true)
 * @return void Modifies the image resource directly
 */
function dthAtkinson($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffer for current and next rows
    $currentErrors = array_fill(0, $width, 0);
    $nextErrors = array_fill(0, $width, 0);
    $nextNextErrors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        // Reset next row errors
        for ($i = 0; $i < $width; $i++) {
            $nextErrors[$i] = 0;
            if ($y + 1 < $height) {
                $nextNextErrors[$i] = 0;
            }
        }
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $currentErrors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
            
            // Distribute error to neighboring pixels
            $errorFraction = $rb ? (1/16) : (1/8);
            
            // Right neighbors
            if ($x + 1 < $width) {
                $nextErrors[$x + 1] += $error * $errorFraction;
            }
            if ($x + 2 < $width) {
                $nextErrors[$x + 2] += $error * $errorFraction;
            }
            
            // Below neighbors
            if ($y + 1 < $height) {
                if ($x > 0) {
                    $nextErrors[$x - 1] += $error * $errorFraction;
                }
                $nextErrors[$x] += $error * $errorFraction;
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * $errorFraction;
                }
            }
            
            // Two rows below
            if ($y + 2 < $height) {
                $nextNextErrors[$x] += $error * $errorFraction;
            }
        }
        
        // Shift error buffers
        $temp = $currentErrors;
        $currentErrors = $nextErrors;
        $nextErrors = $nextNextErrors;
        $nextNextErrors = $temp;
    }
}

/**
 * Apply Jarvis, Judice & Ninke dithering to an image
 * This function implements the Jarvis error diffusion algorithm
 * with optional reduced bleeding mode
 * 
 * @param resource $image Image resource to process
 * @param int $levels Number of grayscale levels
 * @param bool $rb Reduce bleeding flag (default: true)
 * @return void Modifies the image resource directly
 */
function dthJarvis($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffers for current and next two rows
    $currentErrors = array_fill(0, $width, 0);
    $nextErrors = array_fill(0, $width, 0);
    $nextNextErrors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        // Reset next row errors
        for ($i = 0; $i < $width; $i++) {
            $nextErrors[$i] = 0;
            if ($y + 1 < $height) {
                $nextNextErrors[$i] = 0;
            }
        }
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $currentErrors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
            
            // Distribute error to neighboring pixels
            if ($rb) {
                // Reduced bleeding mode - use half the standard coefficients (1/96 instead of 1/48)
                // Row below: 7/96, 5/96, 3/96, 5/96, 7/96
                if ($x - 2 >= 0) {
                    $nextErrors[$x - 2] += $error * (7/96);
                }
                if ($x - 1 >= 0) {
                    $nextErrors[$x - 1] += $error * (5/96);
                }
                $nextErrors[$x] += $error * (3/96);
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * (5/96);
                }
                if ($x + 2 < $width) {
                    $nextErrors[$x + 2] += $error * (7/96);
                }
                
                // Two rows below: 3/96, 5/96, 7/96, 5/96, 3/96
                if ($y + 1 < $height) {
                    if ($x - 2 >= 0) {
                        $nextNextErrors[$x - 2] += $error * (3/96);
                    }
                    if ($x - 1 >= 0) {
                        $nextNextErrors[$x - 1] += $error * (5/96);
                    }
                    $nextNextErrors[$x] += $error * (7/96);
                    if ($x + 1 < $width) {
                        $nextNextErrors[$x + 1] += $error * (5/96);
                    }
                    if ($x + 2 < $width) {
                        $nextNextErrors[$x + 2] += $error * (3/96);
                    }
                }
            } else {
                // Standard Jarvis coefficients (1/48)
                // Row below: 7/48, 5/48, 3/48, 5/48, 7/48
                if ($x - 2 >= 0) {
                    $nextErrors[$x - 2] += $error * (7/48);
                }
                if ($x - 1 >= 0) {
                    $nextErrors[$x - 1] += $error * (5/48);
                }
                $nextErrors[$x] += $error * (3/48);
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * (5/48);
                }
                if ($x + 2 < $width) {
                    $nextErrors[$x + 2] += $error * (7/48);
                }
                
                // Two rows below: 3/48, 5/48, 7/48, 5/48, 3/48
                if ($y + 1 < $height) {
                    if ($x - 2 >= 0) {
                        $nextNextErrors[$x - 2] += $error * (3/48);
                    }
                    if ($x - 1 >= 0) {
                        $nextNextErrors[$x - 1] += $error * (5/48);
                    }
                    $nextNextErrors[$x] += $error * (7/48);
                    if ($x + 1 < $width) {
                        $nextNextErrors[$x + 1] += $error * (5/48);
                    }
                    if ($x + 2 < $width) {
                        $nextNextErrors[$x + 2] += $error * (3/48);
                    }
                }
            }
        }
        
        // Shift error buffers
        $temp = $currentErrors;
        $currentErrors = $nextErrors;
        $nextErrors = $nextNextErrors;
        $nextNextErrors = $temp;
    }
}

/**
 * Apply Stucki dithering to an image
 * This function implements the Stucki error diffusion algorithm
 * with optional reduced bleeding mode
 * 
 * @param resource $image Image resource to process
 * @param int $levels Number of grayscale levels
 * @param bool $rb Reduce bleeding flag (default: true)
 * @return void Modifies the image resource directly
 */
function dthStucki($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffers for current and next two rows
    $currentErrors = array_fill(0, $width, 0);
    $nextErrors = array_fill(0, $width, 0);
    $nextNextErrors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        // Reset next row errors
        for ($i = 0; $i < $width; $i++) {
            $nextErrors[$i] = 0;
            if ($y + 1 < $height) {
                $nextNextErrors[$i] = 0;
            }
        }
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $currentErrors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
            
            // Distribute error to neighboring pixels
            if ($rb) {
                // Reduced bleeding mode - use half the standard coefficients
                // Row below: 8/84, 4/84, 2/84, 4/84, 8/84
                if ($x - 2 >= 0) {
                    $nextErrors[$x - 2] += $error * (8/84);
                }
                if ($x - 1 >= 0) {
                    $nextErrors[$x - 1] += $error * (4/84);
                }
                $nextErrors[$x] += $error * (2/84);
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * (4/84);
                }
                if ($x + 2 < $width) {
                    $nextErrors[$x + 2] += $error * (8/84);
                }
                
                // Two rows below: 2/84, 4/84, 8/84, 4/84, 2/84
                if ($y + 1 < $height) {
                    if ($x - 2 >= 0) {
                        $nextNextErrors[$x - 2] += $error * (2/84);
                    }
                    if ($x - 1 >= 0) {
                        $nextNextErrors[$x - 1] += $error * (4/84);
                    }
                    $nextNextErrors[$x] += $error * (8/84);
                    if ($x + 1 < $width) {
                        $nextNextErrors[$x + 1] += $error * (4/84);
                    }
                    if ($x + 2 < $width) {
                        $nextNextErrors[$x + 2] += $error * (2/84);
                    }
                }
            } else {
                // Standard Stucki coefficients
                // Row below: 8/42, 4/42, 2/42, 4/42, 8/42
                if ($x - 2 >= 0) {
                    $nextErrors[$x - 2] += $error * (8/42);
                }
                if ($x - 1 >= 0) {
                    $nextErrors[$x - 1] += $error * (4/42);
                }
                $nextErrors[$x] += $error * (2/42);
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * (4/42);
                }
                if ($x + 2 < $width) {
                    $nextErrors[$x + 2] += $error * (8/42);
                }
                
                // Two rows below: 2/42, 4/42, 8/42, 4/42, 2/42
                if ($y + 1 < $height) {
                    if ($x - 2 >= 0) {
                        $nextNextErrors[$x - 2] += $error * (2/42);
                    }
                    if ($x - 1 >= 0) {
                        $nextNextErrors[$x - 1] += $error * (4/42);
                    }
                    $nextNextErrors[$x] += $error * (8/42);
                    if ($x + 1 < $width) {
                        $nextNextErrors[$x + 1] += $error * (4/42);
                    }
                    if ($x + 2 < $width) {
                        $nextNextErrors[$x + 2] += $error * (2/42);
                    }
                }
            }
        }
        
        // Shift error buffers
        $temp = $currentErrors;
        $currentErrors = $nextErrors;
        $nextErrors = $nextNextErrors;
        $nextNextErrors = $temp;
    }
}

/**
 * Apply Bayer 2x2 ordered dithering to an image
 * This function implements a simple ordered dithering algorithm
 * using a 2x2 threshold matrix
 * 
 * @param resource $image Image resource to process
 * @param int $levels Number of grayscale levels
 * @return void Modifies the image resource directly
 */
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

/**
 * Apply Burkes dithering to an image
 * This function implements the Burkes error diffusion algorithm
 * with optional reduced bleeding mode
 * 
 * @param resource $image Image resource to process
 * @param int $levels Number of grayscale levels
 * @param bool $rb Reduce bleeding flag (default: true)
 * @return void Modifies the image resource directly
 */
function dthBurkes($image, $levels, $rb = true) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    // Create error diffusion buffers for current and next row
    $currentErrors = array_fill(0, $width, 0);
    $nextErrors = array_fill(0, $width, 0);
    
    for ($y = 0; $y < $height; $y++) {
        // Reset next row errors
        for ($i = 0; $i < $width; $i++) {
            $nextErrors[$i] = 0;
        }
        
        for ($x = 0; $x < $width; $x++) {
            // Get original pixel value
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF;
            
            // Add error from previous row
            $gray = $gray + $currentErrors[$x];
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            // Calculate quantization error
            $error = $gray - $quantized;
            
            // Set the quantized pixel
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
            
            // Distribute error to neighboring pixels
            if ($rb) {
                // Reduced bleeding mode - use half the standard coefficients
                if ($x - 2 >= 0) {
                    $nextErrors[$x - 2] += $error * (8/64);
                }
                if ($x - 1 >= 0) {
                    $nextErrors[$x - 1] += $error * (4/64);
                }
                $nextErrors[$x] += $error * (2/64);
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * (4/64);
                }
                if ($x + 2 < $width) {
                    $nextErrors[$x + 2] += $error * (8/64);
                }
            } else {
                // Standard Burkes coefficients
                if ($x - 2 >= 0) {
                    $nextErrors[$x - 2] += $error * (8/32);
                }
                if ($x - 1 >= 0) {
                    $nextErrors[$x - 1] += $error * (4/32);
                }
                $nextErrors[$x] += $error * (2/32);
                if ($x + 1 < $width) {
                    $nextErrors[$x + 1] += $error * (4/32);
                }
                if ($x + 2 < $width) {
                    $nextErrors[$x + 2] += $error * (8/32);
                }
            }
        }
        
        // Swap error buffers
        $temp = $currentErrors;
        $currentErrors = $nextErrors;
        $nextErrors = $temp;
    }
}

/**
 * Apply simple quantization (no dithering) to an image
 * This function performs basic grayscale quantization without error diffusion
 * 
 * @param resource $image Image resource to process
 * @param int $levels Number of grayscale levels
 * @return void Modifies the image resource directly
 */
function dthNone($image, $levels) {
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate quantization step
    $step = 255 / ($levels - 1);
    
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($image, $x, $y);
            $gray = ($rgb >> 16) & 0xFF; // Get grayscale value
            
            // Quantize to specified number of levels
            $quantized = round(round($gray / $step) * $step);
            // Clamp to valid range
            $quantized = max(0, min(255, $quantized));
            
            $newColor = imagecolorallocate($image, $quantized, $quantized, $quantized);
            imagesetpixel($image, $x, $y, $newColor);
        }
    }
}

/**
 * Display the main upload form for the DitherBox application
 */
function displayForm() {
    ?>
    <h1>DitherBox</h1>
    
    <form method="POST" action="" enctype="multipart/form-data">
        <div>
            <label for="image_source">Image source:</label>
            <select id="image_source" name="image_source" onchange="toggleSourceFields()">
                <option value="url">Via URL</option>
                <option value="file">Upload file</option>
                <option value="collection">Collection</option>
            </select>
        </div>
        
        <div id="url_field">
            <label for="url_input">Image URL:</label>
            <input type="url" id="url_input" name="url" placeholder="https://example.com/image.jpg">
            <input type="hidden" name="ui" value="1">
        </div>
        
        <div id="file_field" style="display:none">
            <label for="image">Select image file:</label>
            <input type="file" id="image" name="image" accept="image/*">
            <input type="hidden" name="ui" value="1">
        </div>
        
        <div id="collection_field" style="display:none">
            <label for="col">Collection:</label>
            <select id="col" name="col">
                <?php
                global $collections;
                foreach ($collections as $id => $collection) {
                    echo '<option value="' . $id . '">' . $collection['name'] . '</option>';
                }
                ?>
                <option value="any">Random collection</option>
            </select>
            <input type="hidden" name="ui" value="1">
        </div>
        
        <div>
            <label for="fmt">Output format:</label>
            <select id="fmt" name="fmt">
                <option value="png">PNG</option>
                <option value="jpg">JPG</option>
                <option value="ppm">PPM</option>
                <option value="pbm">PBM</option>
                <option value="gif">GIF</option>
            </select>
        </div>
        
        <div>
            <label for="bits">Grayscale bits: <span id="bits_value">1</span></label>
            <input type="range" id="bits" name="bits" min="1" max="8" value="1" oninput="document.getElementById('bits_value').textContent = this.value">
        </div>
        
        <div>
            <label for="levels">Grayscale levels (overrides bits):</label>
            <input type="number" id="levels" name="lvl" min="2" max="256" placeholder="2-256">
        </div>
        
        <div>
            <label for="ditherMethod">Dithering method:</label>
            <select id="ditherMethod" name="dth">
                <option value="none">None</option>
                <?php
                global $dthMethods;
                foreach ($dthMethods as $id => $name) {
                    echo '<option value="' . $id . '">' . $name . '</option>';
                }
                ?>
            </select>
        </div>
        
        <div>
            <label>
                <input type="checkbox" id="rb" name="rb" value="1" checked>
                Reduce color bleeding
            </label>
        </div>
        
        <div>
            <label for="res">Resolution (WxH):</label>
            <input type="text" id="res" name="res" value="400x300" placeholder="400x300">
        </div>
        
        <input type="submit" value="Process Image">
    </form>
    
    <p class="note">Note: DitherBox processes images with customizable dithering and grayscale levels.</p>
    <?php
}

/**
 * Display the processed image result
 */
function displayResult($base64Image, $tgtWidth, $tgtHeight, $fmt, $bits, $dth, $rb) {
    ?>
    <h1>DitherBox Result</h1>
    
    <div>
        <img src="<?php echo $base64Image; ?>" alt="Processed Image" class="result-image" style="width: 100%; max-width: <?php echo $tgtWidth; ?>px; height: auto; image-rendering: pixelated;">
    </div>
    
    <div>
        <h2>Image Details</h2>
        <p>
            <small>
            <?php echo strtoupper($fmt); ?> |
            <?php echo $tgtWidth; ?>x<?php echo $tgtHeight; ?> |
            <?php 
            // Display either levels or bits depending on which was used
            if (isset($_GET['lvl']) && intval($_GET['lvl']) > 0) {
                echo $_GET['lvl'] . ' levels';
            } else {
                echo $bits . ' bits';
            }
            ?> |
            <?php global $dthMethods; echo isset($dthMethods[$dth]) ? $dthMethods[$dth] : $dth; ?> |
            <?php echo $rb ? 'reduce bleeding' : ''; ?>
            </small>
        </p>
    </div>
    <?php
}

// Process image if needed
if (isset($_GET['col']) || isset($_GET['url']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $processedImage = processImage($imageData, $levels, $tgtWidth, $tgtHeight, $dth, $rb);
        
        // Check if we should display the form or just return the image
        $showForm = isset($_POST['ui']) && $_POST['ui'] == '1';
        
        if ($showForm) {
            // Convert image to base64 for embedding
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
            
            // Get the image data for embedding
            $imageData = file_get_contents($tempFile);
            unlink($tempFile);
            
            // Get the MIME type for the image
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $imageData);
            finfo_close($finfo);
            
            // Convert image data to base64 for embedding
            $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            
            // Clean up
            imagedestroy($processedImage);
            
            // Display the result with form
            displayWebPage("DitherBox - Result", function() use ($base64Image, $tgtWidth, $tgtHeight, $fmt, $bits, $dth, $rb) {
                displayResult($base64Image, $tgtWidth, $tgtHeight, $fmt, $bits, $dth, $rb);
                displayForm();
            });
        } else {
            // Set appropriate content type header and output image directly
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
                    // Output PPM header
                    echo "P6\n{$tgtWidth} {$tgtHeight}\n255\n";
                    // Output pixel data
                    for ($y = 0; $y < $tgtHeight; $y++) {
                        for ($x = 0; $x < $tgtWidth; $x++) {
                            $rgb = imagecolorat($processedImage, $x, $y);
                            $r = ($rgb >> 16) & 0xFF;
                            $g = ($rgb >> 8) & 0xFF;
                            $b = $rgb & 0xFF;
                            echo chr($r) . chr($g) . chr($b);
                        }
                    }
                    break;
                case 'pbm':
                    header('Content-Type: image/x-portable-bitmap');
                    // Output PBM header
                    echo "P4\n{$tgtWidth} {$tgtHeight}\n";
                    // Output pixel data (1 bit per pixel)
                    for ($y = 0; $y < $tgtHeight; $y++) {
                        $byte = 0;
                        $bitCount = 0;
                        for ($x = 0; $x < $tgtWidth; $x++) {
                            $rgb = imagecolorat($processedImage, $x, $y);
                            $gray = ($rgb >> 16) & 0xFF;
                            // Set bit if pixel is white (above threshold)
                            if ($gray > 127) {
                                $byte |= (1 << (7 - $bitCount));
                            }
                            $bitCount++;
                            // Output byte when we have 8 bits
                            if ($bitCount == 8) {
                                echo chr($byte);
                                $byte = 0;
                                $bitCount = 0;
                            }
                        }
                        // Output remaining bits in the last byte
                        if ($bitCount > 0) {
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
            exit;
        }
        
    } catch (Exception $e) {
        // For GET requests with parameters, output error directly
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (isset($_GET['col']) || isset($_GET['url']))) {
            header('Content-Type: text/plain');
            echo 'Error: ' . $e->getMessage();
            exit;
        }
        // For POST requests, store error for display
        $error = $e->getMessage();
    }
}

// Display the page for POST requests or when no parameters are provided
displayWebPage($error ? "DitherBox - Error" : "DitherBox", function() use ($error) {
    if ($error) {
        ?>
        <h1>DitherBox Error</h1>
        
        <div class="error" style="color: red; padding: 1rem; border: 1px solid red; border-radius: 4px; background-color: #ffe6e6;">
            Error: <?php echo $error; ?>
        </div>
        <?php
    }
    
    displayForm();
});

/**
 * Display a web page with common HTML structure
 * This function provides a consistent layout for all pages in the application
 * 
 * @param string $title The page title
 * @param callable $contentCallback Function that outputs the page content
 * @return void Outputs HTML directly to the browser
 */
function displayWebPage($title, $contentCallback) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $title; ?></title>
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
            <?php $contentCallback(); ?>
        </main>
        
        <script>
            function toggleSourceFields() {
                var source = document.getElementById('image_source');
                if (!source) return;
                
                source = source.value;
                var urlField = document.getElementById('url_field');
                var fileField = document.getElementById('file_field');
                var collectionField = document.getElementById('collection_field');
                
                if (urlField) urlField.style.display = source === 'url' ? 'block' : 'none';
                if (fileField) fileField.style.display = source === 'file' ? 'block' : 'none';
                if (collectionField) collectionField.style.display = source === 'collection' ? 'block' : 'none';
            }
            
            // Initialize the form fields based on the default selection
            document.addEventListener('DOMContentLoaded', function() {
                toggleSourceFields();
            });
        </script>
    </body>
    </html>
    <?php
}
?>
