<?php
// Unified Database Configuration
$db_config = [
    'anime' => [
        'servername' => "localhost",
        'username' => "root",
        'password' => "",
        'port' => "3306",
        'database' => "anime"
    ],
    'shows' => [
        'servername' => "localhost",
        'username' => "root",
        'password' => "",
        'database' => "show_tracker"
    ],
    'manga' => [
        'servername' => "localhost",
        'username' => "root",
        'password' => "",
        'database' => "manga_tracker"
    ]
];

// Handle Image Uploads
if (isset($_GET['action']) && $_GET['action'] === 'image_upload') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $uploadDir = "uploads/$target_app/";
    
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    try {
        // Check for regular file upload
        if (isset($_FILES['image'])) {
            $file = $_FILES['image'];
            
            // Validate file
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
            
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            
            if (!array_key_exists($mimeType, $allowedTypes)) {
                throw new Exception('Invalid file type');
            }
            
            $extension = $allowedTypes[$mimeType];
            $filename = uniqid($target_app . '_', true) . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception('Failed to save file');
            }
            
            echo json_encode([
                'success' => true,
                'filename' => $destination
            ]);
            exit;
        }
        
        // Check for base64 clipboard data
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (isset($data['image'])) {
            $imageData = $data['image'];
            
            // Extract image type and data
            if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
                $imageType = $matches[1];
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
                $imageData = str_replace(' ', '+', $imageData);
                $decodedImage = base64_decode($imageData);
                
                if ($decodedImage === false) {
                    throw new Exception('Failed to decode image data');
                }
                
                $allowedTypes = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
                if (!in_array($imageType, $allowedTypes)) {
                    throw new Exception('Invalid image type');
                }
                
                $filename = uniqid($target_app . '_', true) . '.' . $imageType;
                $destination = $uploadDir . $filename;
                
                if (file_put_contents($destination, $decodedImage)) {
                    echo json_encode([
                        'success' => true,
                        'filename' => $destination
                    ]);
                    exit;
                } else {
                    throw new Exception('Failed to save image file');
                }
            } else {
                throw new Exception('Invalid image format');
            }
        }
        
        throw new Exception('No valid image provided');

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle Database Connections
if (isset($_GET['action']) && $_GET['action'] === 'db_connection') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $config = $db_config[$target_app];
    
    $conn = new mysqli(
        $config['servername'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port'] ?? 3306
    );

    if ($conn->connect_error) {
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]));
    }

    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data) {
        die(json_encode([
            'success' => false,
            'message' => 'Invalid input data'
        ]));
    }

    // Validate required fields
    $required = ['title', 'year', 'season', 'image', 'date'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            die(json_encode([
                'success' => false,
                'message' => "Missing required field: $field"
            ]));
        }
    }

    // Sanitize data
    $title = $conn->real_escape_string($data['title']);
    $year = (int)$data['year'];
    $season = $conn->real_escape_string($data['season']);
    $image = $conn->real_escape_string($data['image']);
    $date = $conn->real_escape_string($data['date']);
    $synopsis = isset($data['synopsis']) ? $conn->real_escape_string($data['synopsis']) : null;

    try {
        $stmt = $conn->prepare("INSERT INTO main_db (Title, Year, Season, Img, DateR, Synopsis) VALUES (?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("sissss", 
            $title,
            $year,
            $season,
            $image,
            $date,
            $synopsis
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        // Also add to shows database if requested
        if (isset($data['add_to_watchlist']) && $data['add_to_watchlist']) {
            try {
                $shows_config = $db_config['shows'];
                $shows_conn = new mysqli(
                    $shows_config['servername'],
                    $shows_config['username'],
                    $shows_config['password'],
                    $shows_config['database']
                );

                if ($shows_conn->connect_error) {
                    throw new Exception('Shows DB connection failed: ' . $shows_conn->connect_error);
                }

                // Prepare insert statement for shows
                $shows_stmt = $shows_conn->prepare("INSERT INTO shows (title, type, image_path, status) VALUES (?, 'anime', ?, 'unwatched')");
                if (!$shows_stmt) {
                    throw new Exception('Shows prepare failed: ' . $shows_conn->error);
                }
                
                $shows_stmt->bind_param("ss", $title, $image);
                
                if (!$shows_stmt->execute()) {
                    throw new Exception('Shows execute failed: ' . $shows_stmt->error);
                }
                
                $shows_stmt->close();
                $shows_conn->close();

            } catch (Exception $e) {
                // Log error but don't fail the main operation
                error_log('Error adding to shows: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Anime added successfully',
            'Rec_Num' => $stmt->insert_id
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } finally {
        if (isset($stmt)) $stmt->close();
        $conn->close();
    }
    exit;
}

// Handle Anime Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete_anime') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $config = $db_config[$target_app];
    
    $conn = new mysqli(
        $config['servername'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port'] ?? 3306
    );

    if ($conn->connect_error) {
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]));
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['id'])) {
        die(json_encode([
            'success' => false,
            'message' => 'Invalid input data'
        ]));
    }

    $id = (int)$data['id'];
    
    try {
        // First get the image path to delete the file
        $sql = "SELECT Img FROM main_db WHERE Rec_Num = $id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (file_exists($row['Img'])) {
                unlink($row['Img']);
            }
        }
        
        // Then delete the record
        $sql = "DELETE FROM main_db WHERE Rec_Num = $id";
        if ($conn->query($sql)) {
            echo json_encode([
                'success' => true,
                'message' => 'Anime deleted successfully'
            ]);
        } else {
            throw new Exception('Delete failed: ' . $conn->error);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } finally {
        $conn->close();
    }
    exit;
}

// Handle Get Anime Details
if (isset($_GET['action']) && $_GET['action'] === 'get_anime') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $config = $db_config[$target_app];
    
    $conn = new mysqli(
        $config['servername'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port'] ?? 3306
    );

    if ($conn->connect_error) {
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]));
    }

    $id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT * FROM main_db WHERE Rec_Num = $id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $anime = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'anime' => $anime
            ]);
        } else {
            throw new Exception('Anime not found');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } finally {
        $conn->close();
    }
    exit;
}

// Handle Anime Update - FIXED VERSION
if (isset($_GET['action']) && $_GET['action'] === 'update_anime') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $config = $db_config[$target_app];
    
    $conn = new mysqli(
        $config['servername'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port'] ?? 3306
    );

    if ($conn->connect_error) {
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]));
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['id'])) {
        die(json_encode([
            'success' => false,
            'message' => 'Invalid input data'
        ]));
    }

    $id = (int)$data['id'];
    $title = $data['title'];
    $year = (int)$data['year'];
    $season = $data['season'];
    $date = $data['date'];
    $synopsis = isset($data['synopsis']) ? $data['synopsis'] : null;

    try {
        // Use prepared statement to prevent SQL injection
        if ($synopsis !== null) {
            $stmt = $conn->prepare("UPDATE main_db SET Title = ?, Year = ?, Season = ?, DateR = ?, Synopsis = ? WHERE Rec_Num = ?");
            $stmt->bind_param("sisssi", $title, $year, $season, $date, $synopsis, $id);
        } else {
            $stmt = $conn->prepare("UPDATE main_db SET Title = ?, Year = ?, Season = ?, DateR = ?, Synopsis = NULL WHERE Rec_Num = ?");
            $stmt->bind_param("sissi", $title, $year, $season, $date, $id);
        }
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Update failed: ' . $stmt->error);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Anime updated successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } finally {
        if (isset($stmt)) $stmt->close();
        $conn->close();
    }
    exit;
}

// ANIME TRACKER SYSTEM
$con2 = new mysqli(
    $db_config['anime']['servername'],
    $db_config['anime']['username'],
    $db_config['anime']['password'],
    $db_config['anime']['database'],
    $db_config['anime']['port'] ?? 3306
);

if ($con2->connect_error) {
    echo "<script> alert('Connection Error'); </script>";
    die("Connection failed: " . $con2->connect_error);
}

// Seasonal theme setup
$Y = date('Y');
$themeBG = "";
$seasonGifs = [
    'summer' => "https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/f/ed8839e8-3dcb-4bf1-b158-30dcd13e9589/dbgk12z-b8175564-549b-4bf6-a9bc-e3cd668811fb.gif?token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1cm46YXBwOjdlMGQxODg5ODIyNjQzNzNhNWYwZDQxNWVhMGQyNmUwIiwiaXNzIjoidXJuOmFwcDo3ZTBkMTg4OTgyMjY0MzczYTVmMGQ0MTVlYTBkMjZlMCIsIm9iaiI6W1t7InBhdGgiOiJcL2ZcL2VkODgzOWU4LTNkY2ItNGJmMS1iMTU4LTMwZGNkMTNlOTU4OVwvZGJnazEyei1iODE3NTU2NC01NDliLTRiZjYtYTliYy1lM2NkNjY4ODExZmIuZ2lmIn1dXSwiYXVkIjpbInVybjpzZXJ2aWNlOmZpbGUuZG93bmxvYWQiXX0.-oUjRC5dqwc4oF4JhMR8Qazb1w8bRA-bNiLOADFgZh8",
    'winter' => "https://supernaturalhippie.com/wp-content/uploads/2019/12/snowflakes-moon-and-mountains-fantasy-gif.gif?w=1200",
    'spring' => "https://mir-s3-cdn-cf.behance.net/project_modules/hd/2c0aa8139400419.622f4c3445217.gif",
    'fall' => "https://giffiles.alphacoders.com/183/18358.gif"
];

date_default_timezone_set('Asia/Tokyo');
$mo = date('m');

if ($mo >= 3 && $mo <= 5) {
    $themeBG = $seasonGifs['spring'];
    $var = "Spring";
    $currentSeason = "Spring";
} elseif ($mo >= 6 && $mo <= 8) {
    $themeBG = $seasonGifs['summer'];
    $var = "Summer";
    $currentSeason = "Summer";
} elseif ($mo >= 9 && $mo <= 11) {
    $themeBG = $seasonGifs['fall'];
    $var = "Fall";
    $currentSeason = "Fall";
} else {
    $themeBG = $seasonGifs['winter'];
    $var = "Winter";
    $currentSeason = "Winter";
}

// Function to determine season based on date
function getSeasonFromDate($date) {
    $month = date('n', strtotime($date));
    
    if ($month >= 3 && $month <= 5) {
        return 'Spring';
    } elseif ($month >= 6 && $month <= 8) {
        return 'Summer';
    } elseif ($month >= 9 && $month <= 11) {
        return 'Fall';
    } else {
        return 'Winter';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="icon.png">
    
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anime Season Tracker</title>
    <style>
        :root {
            --primary-color: #ff6b6b;
            --secondary-color: #4ecdc4;
            --dark-color: #292f36;
            --light-color: #f7fff7;
            --overlay-dark: rgba(0, 0, 0, 0.7);
            --overlay-light: rgba(255, 255, 255, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body.anime-app {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #333;
            background-image: url(<?php echo $themeBG; ?>);
            background-attachment: fixed;
            background-size: cover;
            background-position: center;
            color: white;
            min-height: 100vh;
            transition: background-image 0.5s ease;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            text-align: center;
            padding: 30px 0;
        }

        h1 {
            font-size: 3rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }

        .season-display {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--light-color);
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7);
            margin-bottom: 30px;
        }

        .button-group {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
        }

        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 1rem;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 200px;
        }

        button:hover {
            background-color: #ff5252;
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(0, 0, 0, 0.15);
        }

        button:active {
            transform: translateY(0);
        }

        .btn {
            padding: 8px 15px;
            width: 150px;
        }

        .card {
            background-color: var(--overlay-dark);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .search-card {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        .add-card {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        td {
            padding: 10px;
            vertical-align: middle;
        }

        input, select, textarea {
            padding: 10px;
            width: 100%;
            border-radius: 5px;
            border: 1px solid #ddd;
            background-color: rgba(255, 255, 255, 0.9);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            box-shadow: 0 0 5px var(--secondary-color);
        }

        .search-result {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
        }

        .anime-item {
            background-color: var(--overlay-dark);
            border-radius: 10px;
            overflow: hidden;
            width: 100%;
            max-width: 600px;
            display: flex;
            transition: transform 0.3s ease;
        }

        .anime-item:hover {
            transform: scale(1.02);
        }

        .anime-poster {
            width: 225px;
            height: 320px;
            object-fit: cover;
        }

        .anime-info {
            padding: 20px;
            flex: 1;
        }

        .anime-title {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .anime-meta {
            color: #ccc;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .no-results {
            text-align: center;
            padding: 50px;
            font-size: 1.2rem;
            color: #ccc;
        }

        #result {
            margin-top: 20px;
            text-align: center;
        }

        #result img {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
            margin-top: 15px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .hidden {
            display: none !important;
        }

        .upload-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 15px;
        }

        .file-upload-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-input-label {
            padding: 8px 15px;
            background-color: var(--secondary-color);
            color: white;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
            text-align: center;
        }

        .file-input-label:hover {
            background-color: #3db9b0;
        }

        @media (max-width: 768px) {
            .button-group {
                flex-direction: column;
                align-items: center;
            }
            
            .anime-item {
                flex-direction: column;
            }
            
            .anime-poster {
                width: 100%;
                height: auto;
            }
        }
        
        .app-navigation {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .app-navigation button {
            background-color: #4a8fe7;
            width: auto;
        }
        
        .watchlist-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
            padding: 10px;
            background-color: var(--overlay-light);
            border-radius: 5px;
        }
        
        .watchlist-checkbox input {
            width: auto;
        }

        /* Edit and Delete buttons */
        .anime-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .edit-btn, .delete-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            width: 100px;
        }

        .edit-btn {
            background-color: #4a8fe7;
            color: white;
        }

        .delete-btn {
            background-color: #e74c3c;
            color: white;
        }

        .edit-btn:hover, .delete-btn:hover {
            opacity: 0.8;
        }

        /* Edit form styles */
        .edit-form {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--overlay-dark);
            padding: 20px;
            border-radius: 10px;
            z-index: 1000;
            width: 80%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.5);
        }

        .edit-form h3 {
            margin-bottom: 15px;
            text-align: center;
        }

        .edit-form table {
            width: 100%;
        }

        .edit-form input, .edit-form select, .edit-form textarea {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 15px;
        }

        .form-actions button {
            padding: 8px 15px;
            width: auto;
        }
        
        .S-Div {
            margin-top: 20px;
            width: 100%;
            word-wrap: break-word;

        }
        
        .synopsis {
            line-height: 1.5;
            font-size: 0.9rem;
            color: #ddd;
            max-height: 150px;
            overflow-y: auto;
            padding: 8px;
            background-color: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }

        .synopsis::-webkit-scrollbar {
          width: 10px;
        }
        
        .no-synopsis {
            font-style: italic;
            color: #888;
            font-size: 0.9rem;
        }
        
        .status-message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            text-align: center;
        }
        
        .success {
            background-color: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }
        
        .error {
            background-color: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }
        
        .clipboard-instructions {
            margin-top: 15px;
            padding: 10px;
            background-color: rgba(255, 107, 107, 0.1);
            border-left: 3px solid var(--primary-color);
            font-size: 0.9rem;
        }
        
        #fileError {
            color: #e74c3c;
            margin-top: 5px;
            font-size: 0.9rem;
            display: none;
        }
        
        .clipboard-indicator {
            margin-top: 10px;
            padding: 8px;
            background-color: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
            border-radius: 4px;
            display: none;
            font-size: 0.9rem;
        }
        
        .automatic-check {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
            padding: 10px;
            background-color: rgba(74, 143, 231, 0.1);
            border-radius: 5px;
        }
        
        .automatic-check input {
            width: auto;
        }
    </style>
</head>
<body class="anime-app">
    <div class="container">
        <div class="app-navigation">
            <button onclick="navigateTo('index')">Home</button>
            <button onclick="navigateTo('shows')">Show Tracker</button>
            <button onclick="navigateTo('manga')">Reading Tracker</button>
        </div>
        
        <header>
            <h1>Anime Season Tracker</h1>
            <div class="season-display">Current Season: <?php echo $var; ?></div>
        </header>

        <div class="button-group">
            
            <form method="POST" id="searchForm">
                <button name="Ebtn">Today's Episodes</button>
            </form>
            <button onclick="btnSearch()">Search Anime</button>
            <button onclick="btnUpload()">Add Anime</button>
        </div>

        <!-- Search Section -->
        <div id="search-section" class="card search-card hidden">
            <form method="POST" id="searchForm">
                <table>
                    <tr>
                        <td>Year</td>
                        <td>Season</td>
                        <td>Week</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>
                            <input required type="number" min="1900" max="<?php echo date('Y'); ?>" 
                                   value="<?php echo $Y; ?>" name="AniYear" id="AniYear">
                        </td>
                        <td>
                            <select name="AniSeason" id="AniSeason">
                                <option value="Summer" <?php echo $currentSeason === 'Summer' ? 'selected' : ''; ?>>Summer</option>
                                <option value="Fall" <?php echo $currentSeason === 'Fall' ? 'selected' : ''; ?>>Fall</option>
                                <option value="Winter" <?php echo $currentSeason === 'Winter' ? 'selected' : ''; ?>>Winter</option>
                                <option value="Spring" <?php echo $currentSeason === 'Spring' ? 'selected' : ''; ?>>Spring</option>                                
                            </select>
                        </td>
                         <td>
                            <select name="AniWeek">
                                <option value="">-</option>
                                <option>Sunday</option>
                                <option>Monday</option>
                                <option>Tuesday</option>
                                <option>Wednesday</option>
                                <option>Thursday</option>
                                <option>Friday</option>
                                <option>Saturday</option>
                           
                            </select>
                        </td>
                        <td>
                            <button class="btn" name="Sbtn">Search</button>
                            <button class="btn" name="Abtn">View All</button>
                        </td>
                    </tr>
                    <tr>
                </table>
            </form>
        </div>

        <!-- Search Results -->
        <br>
        <div id="resDiv" class="search-result">
            <?php
            if(isset($_POST['Sbtn'])) {
                $year = $con2->real_escape_string($_POST['AniYear']);
                $season = $con2->real_escape_string($_POST['AniSeason']);
                
                $week = $con2->real_escape_string($_POST['AniWeek']);
                $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img, Synopsis FROM main_db 
                        WHERE Year='$year' AND Season='$season'";
                if (!empty($week)) {
                    $sql .= " AND DAYNAME(DateR) = '$week'";
                }
                $result = $con2->query($sql);

                if ($result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        if($row['DateR']!='') {
                            $input = $row['DateR'];
                            $date = strtotime($input);
                            $StDate=date('M d, l', $date);
                        } else {
                            $StDate="No Information";
                        }

                        echo '<div class="anime-item">
                                <img class="anime-poster" src="'.$row['Img'].'" alt="'.$row['Title'].'">
                                <div class="anime-info">
                                    <h2 class="anime-title">'.$row['Title'].'</h2>
                                    <div class="anime-meta">'.$row['Season'].' '.$row['Year'].' | Released: '.$StDate.'</div>
                                    <div class="anime-actions">
                                        <button class="edit-btn" onclick="editAnime('.$row['Rec_Num'].')">Edit</button>
                                        <button class="delete-btn" onclick="deleteAnime('.$row['Rec_Num'].')">Delete</button>
                                    </div>
                                    <div class="S-Div">';
                        if (!empty($row['Synopsis'])) {
                            echo '<p class="synopsis">' . nl2br(htmlspecialchars($row['Synopsis'])) . '</p>';
                        } else {
                            echo '<p class="no-synopsis">No synopsis available</p>';
                        }
                        echo '</div>
                                </div>
                              </div>';
                    }
                } else {
                    echo '<div class="no-results">No anime found for '.$season.' '.$year.'</div>';
                }
            }

            if(isset($_POST['Ebtn'])) {
                // Today's Episodes - only show anime from current season and year
                $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img, Synopsis FROM main_db 
                        WHERE Year='$Y' AND Season='$currentSeason'";
                $result = $con2->query($sql);
                
                if ($result->num_rows > 0) {
                    $today = date('l');
                    $hasResults = false;
                    
                    while($row = $result->fetch_assoc()) {
                        if($row['DateR']!='') {
                            $input = $row['DateR'];
                            $date = strtotime($input);
                            $StDate=date('l', $date);
                            
                            if($StDate==$today) {
                                $hasResults = true;
                                echo '<div class="anime-item">
                                        <img class="anime-poster" src="'.$row['Img'].'" alt="'.$row['Title'].'">
                                        <div class="anime-info">
                                            <h2 class="anime-title">'.$row['Title'].'</h2>
                                            <div class="anime-meta">'.$row['Season'].' '.$row['Year'].' | Released: '.$StDate.'</div>
                                            <div class="anime-actions">
                                                <button class="edit-btn" onclick="editAnime('.$row['Rec_Num'].')">Edit</button>
                                                <button class="delete-btn" onclick="deleteAnime('.$row['Rec_Num'].')">Delete</button>
                                            </div>
                                            <div class="S-Div">';
                                if (!empty($row['Synopsis'])) {
                                    echo '<p class="synopsis">' . nl2br(htmlspecialchars($row['Synopsis'])) . '</p>';
                                } else {
                                    echo '<p class="no-synopsis">No synopsis available</p>';
                                }
                                echo '</div>
                                        </div>
                                      </div>';
                            }
                        }
                    }
                    
                    if (!$hasResults) {
                        echo '<div class="no-results">No episodes scheduled for today</div>';
                    }
                } else {
                    echo '<div class="no-results">No anime found for '.$currentSeason.' '.$Y.'</div>';
                }
            }

            if(isset($_POST['Abtn'])) {
                // View All - show all anime
                $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img, Synopsis FROM main_db ORDER BY Year DESC, Season, Title";
                $result = $con2->query($sql);

                if ($result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        if($row['DateR']!='') {
                            $input = $row['DateR'];
                            $date = strtotime($input);
                            $StDate=date('M d, l', $date);
                        } else {
                            $StDate="No Information";
                        }

                        echo '<div class="anime-item">
                                <img class="anime-poster" src="'.$row['Img'].'" alt="'.$row['Title'].'">
                                <div class="anime-info">
                                    <h2 class="anime-title">'.$row['Title'].'</h2>
                                    <div class="anime-meta">'.$row['Season'].' '.$row['Year'].' | Released: '.$StDate.'</div>
                                    <div class="anime-actions">
                                        <button class="edit-btn" onclick="editAnime('.$row['Rec_Num'].')">Edit</button>
                                        <button class="delete-btn" onclick="deleteAnime('.$row['Rec_Num'].')">Delete</button>
                                    </div>
                                    <div class="S-Div">';
                        if (!empty($row['Synopsis'])) {
                            echo '<p class="synopsis">' . nl2br(htmlspecialchars($row['Synopsis'])) . '</p>';
                        } else {
                            echo '<p class="no-synopsis">No synopsis available</p>';
                        }
                        echo '</div>
                                </div>
                              </div>';
                    }
                } else {
                    echo '<div class="no-results">No anime found in database</div>';
                }
            }
            ?>
        </div>

        <!-- Upload Section -->
        <div id="upload-section" class="card add-card hidden">
            <h2>Add New Anime</h2>
            <div id="uploadStatus"></div>
            <form id="uploadForm" enctype="multipart/form-data">
                <table>
                    <tr>
                        <td>Title</td>
                        <td><input required type="text" name="title" id="title"></td>
                    </tr>
                    <tr>
                        <td>Release Date</td>
                        <td><input required type="date" name="date" id="date"></td>
                    </tr>
                    <tr>
                        <td>Synopsis</td>
                        <td><textarea name="synopsis" id="synopsis" rows="4"></textarea></td>
                    </tr>
                    <tr>
                        <td>Image</td>
                        <td>
                            <div class="upload-options">
                                <div class="file-upload-container">
                                    <label for="fileInput" class="file-input-label">Choose File</label>
                                    <input type="file" id="fileInput" name="image" accept="image/*" style="display: none;">
                                    <span id="fileName">No file chosen</span>
                                </div>
                                <div id="fileError">Please select a valid image file</div>
                                
                                <div id="clipboardIndicator" class="clipboard-indicator">
                                    Image captured from clipboard! Fill in the other fields and submit.
                                </div>
                                
                                <div class="automatic-check">
                                    <input type="checkbox" id="autoClipboard" checked>
                                    <label for="autoClipboard">Automatically check for clipboard images</label>
                                </div>
                                
                                <div class="clipboard-instructions">
                                    <strong>Tip:</strong> The system will automatically detect images in your clipboard when you focus on this form.
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div class="watchlist-checkbox">
                                <input type="checkbox" id="addToWatchlist" name="addToWatchlist">
                                <label for="addToWatchlist">Also add to my watchlist</label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <button type="submit" class="btn">Add Anime</button>
                        </td>
                    </tr>
                </table>
            </form>
            
            <div id="result"></div>
        </div>
    </div>

    <!-- Edit Form (initially hidden) -->
    <div id="editForm" class="edit-form hidden">
        <h3>Edit Anime</h3>
        <div id="editStatus"></div>
        <form id="editAnimeForm">
            <input type="hidden" id="editId" name="id">
            <table>
                <tr>
                    <td>Title</td>
                    <td><input required type="text" id="editTitle" name="title"></td>
                </tr>
                <tr>
                    <td>Year</td>
                    <td><input required type="number" id="editYear" name="year" min="1900" max="<?php echo date('Y'); ?>"></td>
                </tr>
                <tr>
                    <td>Season</td>
                    <td>
                        <select required id="editSeason" name="season">
                            <option value="Summer">Summer</option>
                            <option value="Fall">Fall</option>
                            <option value="Winter">Winter</option>
                            <option value="Spring">Spring</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Release Date</td>
                    <td><input required type="date" id="editDate" name="date"></td>
                </tr>
                <tr>
                    <td>Synopsis</td>
                    <td><textarea id="editSynopsis" name="synopsis" rows="4"></textarea></td>
                </tr>
            </table>
            <div class="form-actions">
                <button type="button" class="btn" onclick="cancelEdit()">Cancel</button>
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>
    </div>

    <script>
        // Navigation function
        function navigateTo(app) {
            if (app === 'index') {
                window.location.href = 'index.php';
            } else if (app === 'shows') {
                window.location.href = 'shows.php';
            } else if (app === 'manga') {
                window.location.href = 'manga.php';
            }
        }

        // Toggle sections
        function btnSearch() {
            document.getElementById('search-section').classList.toggle('hidden');
            document.getElementById('upload-section').classList.add('hidden');
        }

        function btnUpload() {
            document.getElementById('upload-section').classList.toggle('hidden');
            document.getElementById('search-section').classList.add('hidden');
            // Clear any existing search results when opening upload form
            document.getElementById('resDiv').innerHTML = '';
            
            // Start checking for clipboard images when upload section is opened
            startClipboardCheck();
        }

        // File input handling
        document.getElementById('fileInput').addEventListener('change', function(e) {
            const fileName = e.target.files[0] ? e.target.files[0].name : 'No file chosen';
            document.getElementById('fileName').textContent = fileName;
            
            // Validate file type
            const file = e.target.files[0];
            if (file) {
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    document.getElementById('fileError').style.display = 'block';
                    e.target.value = '';
                    document.getElementById('fileName').textContent = 'No file chosen';
                } else {
                    document.getElementById('fileError').style.display = 'none';
                }
            }
        });

        // Clipboard image handling
        let clipboardImageData = null;
        let clipboardCheckInterval = null;
        
        // Function to check clipboard for images
        function checkClipboardForImages() {
            if (!document.getElementById('autoClipboard').checked) return;
            
            // Try to read from clipboard
            navigator.permissions.query({name: "clipboard-read"}).then(result => {
                if (result.state === "granted" || result.state === "prompt") {
                    navigator.clipboard.read().then(clipboardItems => {
                        for (const clipboardItem of clipboardItems) {
                            for (const type of clipboardItem.types) {
                                if (type.startsWith('image/')) {
                                    clipboardItem.getType(type).then(blob => {
                                        const reader = new FileReader();
                                        reader.onload = function(event) {
                                            clipboardImageData = event.target.result;
                                            
                                            // Show indicator that image was captured
                                            document.getElementById('clipboardIndicator').style.display = 'block';
                                            
                                            // Auto-submit the form if we have all required fields
                                            const title = document.getElementById('title').value;
                                            const date = document.getElementById('date').value;
                                            
                                            if (title && date) {
                                                document.getElementById('uploadForm').dispatchEvent(new Event('submit'));
                                            }
                                        };
                                        reader.readAsDataURL(blob);
                                    });
                                    break;
                                }
                            }
                        }
                    }).catch(err => {
                        // Clipboard might be empty or not accessible
                        console.log('Clipboard is empty or not accessible');
                    });
                }
            });
        }
        
        // Start checking for clipboard images
        function startClipboardCheck() {
            if (clipboardCheckInterval) {
                clearInterval(clipboardCheckInterval);
            }
            
            // Check immediately
            checkClipboardForImages();
            
            // Then set up interval to check every 2 seconds
            clipboardCheckInterval = setInterval(checkClipboardForImages, 2000);
        }
        
        // Stop checking for clipboard images
        function stopClipboardCheck() {
            if (clipboardCheckInterval) {
                clearInterval(clipboardCheckInterval);
                clipboardCheckInterval = null;
            }
        }
        
        // Set up focus/blur events to manage clipboard checking
        document.getElementById('upload-section').addEventListener('focusin', function() {
            startClipboardCheck();
        });
        
        document.getElementById('upload-section').addEventListener('focusout', function() {
            stopClipboardCheck();
        });

        // Function to determine season based on date
        function getSeasonFromDate(dateString) {
            const date = new Date(dateString);
            const month = date.getMonth() + 1; // JavaScript months are 0-based
            
            if (month >= 3 && month <= 5) {
                return 'Spring';
            } else if (month >= 6 && month <= 8) {
                return 'Summer';
            } else if (month >= 9 && month <= 11) {
                return 'Fall';
            } else {
                return 'Winter';
            }
        }

        // Function to get year from date
        function getYearFromDate(dateString) {
            const date = new Date(dateString);
            return date.getFullYear();
        }

        // Form submission handling
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const title = document.getElementById('title').value;
            const date = document.getElementById('date').value;
            const synopsis = document.getElementById('synopsis').value;
            const addToWatchlist = document.getElementById('addToWatchlist').checked;
            const fileInput = document.getElementById('fileInput');
            
            // Validate required fields
            if (!title || !date) {
                showStatus('Please fill in all required fields', 'error');
                return;
            }
            
            // Automatically determine season and year from date
            const season = getSeasonFromDate(date);
            const year = getYearFromDate(date);
            
            // Check if we have an image from clipboard or file
            if (!clipboardImageData && fileInput.files.length === 0) {
                showStatus('Please select an image or paste one from clipboard', 'error');
                return;
            }
            
            showStatus('Uploading...', '');
            
            // Prepare the upload
            if (clipboardImageData) {
                // Use clipboard image - send as JSON
                const jsonData = {
                    image: clipboardImageData
                };
                
                fetch('?action=image_upload&for=anime', {
                    method: 'POST',
                    body: JSON.stringify(jsonData),
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        addAnimeToDatabase(title, year, season, date, synopsis, data.filename, addToWatchlist);
                    } else {
                        showStatus('Error uploading image: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showStatus('Error: ' + error.message, 'error');
                });
                
            } else {
                // Use file upload - send as FormData
                const formData = new FormData();
                formData.append('image', fileInput.files[0]);
                
                fetch('?action=image_upload&for=anime', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        addAnimeToDatabase(title, year, season, date, synopsis, data.filename, addToWatchlist);
                    } else {
                        showStatus('Error uploading image: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showStatus('Error: ' + error.message, 'error');
                });
            }
        });
        
        function addAnimeToDatabase(title, year, season, date, synopsis, imagePath, addToWatchlist) {
            const jsonData = {
                title: title,
                year: year,
                season: season,
                date: date,
                synopsis: synopsis,
                image: imagePath,
                add_to_watchlist: addToWatchlist
            };
            
            fetch('?action=db_connection&for=anime', {
                method: 'POST',
                body: JSON.stringify(jsonData),
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showStatus('Anime added successfully! Rec_Num: ' + data.Rec_Num, 'success');
                    // Reset form
                    document.getElementById('uploadForm').reset();
                    document.getElementById('fileName').textContent = 'No file chosen';
                    document.getElementById('clipboardIndicator').style.display = 'none';
                    clipboardImageData = null;
                } else {
                    showStatus('Error adding to database: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showStatus('Error: ' + error.message, 'error');
            });
        }
        
        function showStatus(message, type) {
            const statusDiv = document.getElementById('uploadStatus');
            statusDiv.textContent = message;
            statusDiv.className = 'status-message';
            if (type) {
                statusDiv.classList.add(type);
            }
        }
        
        // Edit anime functionality
        function editAnime(id) {
            fetch(`?action=get_anime&for=anime&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const anime = data.anime;
                        document.getElementById('editId').value = anime.Rec_Num;
                        document.getElementById('editTitle').value = anime.Title;
                        document.getElementById('editYear').value = anime.Year;
                        document.getElementById('editSeason').value = anime.Season;
                        document.getElementById('editDate').value = anime.DateR;
                        document.getElementById('editSynopsis').value = anime.Synopsis || '';
                        
                        document.getElementById('editForm').classList.remove('hidden');
                    } else {
                        alert('Error fetching anime details: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                });
        }
        
        function cancelEdit() {
            document.getElementById('editForm').classList.add('hidden');
            document.getElementById('editStatus').textContent = '';
        }
        
        document.getElementById('editAnimeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const id = document.getElementById('editId').value;
            const title = document.getElementById('editTitle').value;
            const year = document.getElementById('editYear').value;
            const season = document.getElementById('editSeason').value;
            const date = document.getElementById('editDate').value;
            const synopsis = document.getElementById('editSynopsis').value;
            
            const jsonData = {
                id: id,
                title: title,
                year: year,
                season: season,
                date: date,
                synopsis: synopsis
            };
            
            fetch('?action=update_anime&for=anime', {
                method: 'POST',
                body: JSON.stringify(jsonData),
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                const statusDiv = document.getElementById('editStatus');
                statusDiv.textContent = data.message;
                statusDiv.className = 'status-message';
                
                if (data.success) {
                    statusDiv.classList.add('success');
                    // Reload the page after a short delay to see the changes
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    statusDiv.classList.add('error');
                }
            })
            .catch(error => {
                const statusDiv = document.getElementById('editStatus');
                statusDiv.textContent = 'Error: ' + error.message;
                statusDiv.className = 'status-message error';
            });
        });
        
        // Delete anime functionality
        function deleteAnime(id) {
            if (confirm('Are you sure you want to delete this anime?')) {
                const jsonData = { id: id };
                
                fetch('?action=delete_anime&for=anime', {
                    method: 'POST',
                    body: JSON.stringify(jsonData),
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Anime deleted successfully');
                        window.location.reload();
                    } else {
                        alert('Error deleting anime: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                });
            }
        }
    </script>
</body>
</html>