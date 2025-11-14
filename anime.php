<?php
session_start();
// Database Configuration
$servername = "localhost";
$username = "root";
$password = "";
$database = "tracker";
$port = "3306";

// Handle Login
if (isset($_GET['action']) && $_GET['action'] === 'login') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $conn = new mysqli($servername, $username, $password, $database, $port);

    if ($conn->connect_error) {
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]));
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || empty($data['username']) || empty($data['password'])) {
        die(json_encode([
            'success' => false,
            'message' => 'Username and password are required'
        ]));
    }

    $username = $conn->real_escape_string($data['username']);
    $password = $conn->real_escape_string(md5($data['password']));

    try {
        $sql = "SELECT * FROM accounts WHERE username = '$username' AND password = '$password'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $_SESSION['login'] = true;
            $_SESSION['username'] = $user['username'];
            $_SESSION['firstname'] = $user['firstname'];
            $_SESSION['lastname'] = $user['lastname'];
            $_SESSION['level'] = $user['level'];
            
            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'username' => $user['username'],
                    'firstname' => $user['firstname'],
                    'lastname' => $user['lastname'],
                    'level' => $user['level']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid username or password'
            ]);
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

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
    exit;
}

// Handle Image Uploads
if (isset($_GET['action']) && $_GET['action'] === 'image_upload') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $uploadDir = "uploads/anime/";
    
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
            $filename = uniqid('anime_', true) . '.' . $extension;
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
                
                $filename = uniqid('anime_', true) . '.' . $imageType;
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

// Handle Database Connections with Link3 fallback
if (isset($_GET['action']) && $_GET['action'] === 'db_connection') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    // Check if user is logged in
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
        die(json_encode([
            'success' => false,
            'message' => 'Authentication required'
        ]));
    }

    $conn = new mysqli($servername, $username, $password, $database, $port);

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
    $synopsis = isset($data['synopsis']) ? $conn->real_escape_string($data['synopsis']) : '';
    $link1 = isset($data['link1']) ? $conn->real_escape_string($data['link1']) : '';
    $link2 = isset($data['link2']) ? $conn->real_escape_string($data['link2']) : '';
    $link3 = isset($data['link3']) ? $conn->real_escape_string($data['link3']) : '';

    try {
        // Check if Link3 column exists, if not use fallback query
        $checkColumn = $conn->query("SHOW COLUMNS FROM main_db LIKE 'Link3'");
        if ($checkColumn->num_rows > 0) {
            // Link3 exists, use full query
            $stmt = $conn->prepare("INSERT INTO main_db (Title, Year, Season, Img, DateR, Synopsis, Link1, Link2, Link3) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("sisssssss", 
                $title,
                $year,
                $season,
                $image,
                $date,
                $synopsis,
                $link1,
                $link2,
                $link3
            );
        } else {
            // Link3 doesn't exist, use fallback query
            $stmt = $conn->prepare("INSERT INTO main_db (Title, Year, Season, Img, DateR, Synopsis, Link1, Link2) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("sissssss", 
                $title,
                $year,
                $season,
                $image,
                $date,
                $synopsis,
                $link1,
                $link2
            );
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
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

    // Check if user is logged in
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
        die(json_encode([
            'success' => false,
            'message' => 'Authentication required'
        ]));
    }

    $conn = new mysqli($servername, $username, $password, $database, $port);

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

// Handle Get Anime Details with Link3 fallback
if (isset($_GET['action']) && $_GET['action'] === 'get_anime') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $conn = new mysqli($servername, $username, $password, $database, $port);

    if ($conn->connect_error) {
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]));
    }

    $id = (int)$_GET['id'];
    
    try {
        // Check if Link3 column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM main_db LIKE 'Link3'");
        if ($checkColumn->num_rows > 0) {
            $sql = "SELECT * FROM main_db WHERE Rec_Num = $id";
        } else {
            // Fallback query without Link3
            $sql = "SELECT Rec_Num, Title, Year, Season, Img, DateR, Synopsis, Link1, Link2 FROM main_db WHERE Rec_Num = $id";
        }
        
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $anime = $result->fetch_assoc();
            // Ensure Link3 exists in the result even if column doesn't exist
            if (!isset($anime['Link3'])) {
                $anime['Link3'] = '';
            }
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

// Handle Anime Update with Link3 fallback
if (isset($_GET['action']) && $_GET['action'] === 'update_anime') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    // Check if user is logged in
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
        die(json_encode([
            'success' => false,
            'message' => 'Authentication required'
        ]));
    }

    $conn = new mysqli($servername, $username, $password, $database, $port);

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
    $title = $conn->real_escape_string($data['title']);
    $year = (int)$data['year'];
    $season = $conn->real_escape_string($data['season']);
    $date = $conn->real_escape_string($data['date']);
    $synopsis = isset($data['synopsis']) ? $conn->real_escape_string($data['synopsis']) : '';
    $link1 = isset($data['link1']) ? $conn->real_escape_string($data['link1']) : '';
    $link2 = isset($data['link2']) ? $conn->real_escape_string($data['link2']) : '';
    $link3 = isset($data['link3']) ? $conn->real_escape_string($data['link3']) : '';

    try {
        // Check if Link3 column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM main_db LIKE 'Link3'");
        
        $sets = [];
        $sets[] = "Title = '$title'";
        $sets[] = "Year = $year";
        $sets[] = "Season = '$season'";
        $sets[] = "DateR = '$date'";
        $sets[] = "Synopsis = '$synopsis'";
        $sets[] = "Link1 = '$link1'";
        $sets[] = "Link2 = '$link2'";
        
        if ($checkColumn->num_rows > 0) {
            $sets[] = "Link3 = '$link3'";
        }
        
        $sql = "UPDATE main_db SET " . implode(', ', $sets) . " WHERE Rec_Num = $id";
        
        if ($conn->query($sql)) {
            echo json_encode([
                'success' => true,
                'message' => 'Anime updated successfully'
            ]);
        } else {
            throw new Exception('Update failed: ' . $conn->error);
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

// ANIME TRACKER SYSTEM - Direct rendering
$con2 = new mysqli($servername, $username, $password, $database, $port);

if ($con2->connect_error) {
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
          <script>Swal.fire({icon:'error',title:'Connection Error',text:'Database connection failed!'});</script>";
    die("Connection failed: " . $con2->connect_error);
}

// Check if Link3 column exists
$link3Exists = $con2->query("SHOW COLUMNS FROM main_db LIKE 'Link3'")->num_rows > 0;

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
} elseif ($mo >= 6 && $mo <= 8) {
    $themeBG = $seasonGifs['summer'];
    $var = "Summer";
} elseif ($mo >= 9 && $mo <= 11) {
    $themeBG = $seasonGifs['fall'];
    $var = "Fall";
} else {
    $themeBG = $seasonGifs['winter'];
    $var = "Winter";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="icon.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anime Watch List</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ... (keep all your existing CSS styles) ... */
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

        .btnSearch, .btnViewAll {
            padding: 8px 15px;
            width: 150px;
            border-radius: 5px;
            background-color: gray;
            color: white;
        }
        
        .btnSearch:hover, .btnViewAll:hover {
            background-color: white;
            color: black;
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
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .anime-title:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        .anime-meta {
            color: #ccc;
            margin-bottom: 5px;
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

        /* Link buttons */
        .anime-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            align-items: center;
        }

        .lnk-btn1, .lnk-btn2, .lnk-btn3 {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.7rem;
            width: auto;
        }

        .lnk-btn1 {
            background-color: purple;
            color: white;
        }

        .lnk-btn2 {
            background-color: deepskyblue;
            color: black;
        }

        .lnk-btn3 {
            background-color: red;
            color: white;
        }

        .lnk-btn1:hover, .lnk-btn2:hover, .lnk-btn3:hover {
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
        
        .delete-action {
            display: flex;
            justify-content: flex-start;
            margin-top: 15px;
        }
        
        .delete-btn {
            background-color: #e74c3c;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.7rem;
            width: auto;
        }

        .cancelbtn, .savebtn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.7rem;
            width: auto;
        }

        .savebtn {
            background-color: rgba(52, 140, 235);
        }
        
        .delete-btn:hover {
            background-color: #c0392b;
        }
        
        .S-Div {
            margin-top: 20px;
            
            word-wrap: break-word;
            height: 130px;
            overflow: auto;
            scrollbar-width: thin;
            scrollbar-color: #888 transparent;
            text-align: justify;
            font-size: 13px;
        }

        /* Overlay for modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 999;
        }
        
        .Unrel {
            font-size: 13px;
            padding: 5px 10px;
            background-color: orangered;
            border-radius: 4px;
            font-weight: solid;
        }

        /* Login Modal Styles */
        .login-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--overlay-dark);
            padding: 30px;
            border-radius: 15px;
            z-index: 1001;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }

        .login-modal h2 {
            text-align: center;
            margin-bottom: 20px;
            color: var(--primary-color);
        }

        .login-form table {
            width: 100%;
        }

        .login-form td {
            padding: 8px;
        }

        .login-actions {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 20px;
        }

        .login-actions button {
            width: 48%;
            padding: 10px;
        }

        .user-info {
            position: absolute;
            top: 20px;
            right: 20px;
            background-color: var(--overlay-dark);
            padding: 10px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        .logout-btn {
            background-color: #e74c3c;
            padding: 5px 10px;
            border-radius: 15px;
            cursor: pointer;
            margin-left: 10px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body class="anime-app">
    <!-- Login Modal -->
    <div id="loginModal" class="login-modal hidden">
        <h2>Login</h2>
        <form id="loginForm" class="login-form">
            <table>
                <tr>
                    <td>Username:</td>
                    <td><input type="text" id="login_username" name="username" required></td>
                </tr>
                <tr>
                    <td>Password:</td>
                    <td><input type="password" id="login_password" name="password" required></td>
                </tr>
            </table>
            <div class="login-actions">
                <button type="button" class="cancelbtn" onclick="closeLoginModal()">Cancel</button>
                <button type="submit" class="savebtn">Login</button>
            </div>
        </form>
    </div>

    <!-- User Info Display -->
    <?php if (isset($_SESSION['login']) && $_SESSION['login'] === true): ?>
    <div class="user-info">
        Welcome, <?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?>
        <button class="logout-btn" onclick="logout()">Logout</button>
    </div>
    <?php endif; ?>

    <div class="container">
        <div class="app-navigation">
            <button onclick="navigateToHome()">Home</button>
            <?php if (!isset($_SESSION['login']) || $_SESSION['login'] !== true): ?>
            <button onclick="showLoginModal()">Login</button>
            <?php endif; ?>
        </div>
        
        <header>
            <h1>Anime Watch List</h1>
            <div class="season-display">Current Season: <?php echo $var; ?></div>
        </header>

        <div class="button-group">
            <form method="POST" id="searchForm">
                <button name="Ebtn">Today's Episodes</button>
            </form>
            <button onclick="btnSearch()">Search Anime</button>
            <?php if (isset($_SESSION['login']) && $_SESSION['login'] === true): ?>
            <button onclick="btnUpload()">Add Anime</button>
            <?php endif; ?>
        </div>

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
                            <input required type="number" min="1900" max="<?php echo date('Y')+1; ?>" 
                                   value="<?php echo $Y; ?>" name="AniYear">
                        </td>
                        <td>
                            <select name="AniSeason" id="AniSeason">
                                <option value="Summer">Summer</option>
                                <option value="Fall">Fall</option>
                                <option value="Winter">Winter</option>
                                <option value="Spring">Spring</option>                                
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
                            <button class="btnSearch" name="Sbtn">Search</button>
                            <button class="btnViewAll" name="Abtn">View All</button>
                        </td>
                    </tr>
                </table>
            </form>
        </div>

        <br>
        <div id="resDiv" class="search-result">
            <?php
            // Build query based on whether Link3 exists
            $selectFields = "Rec_Num, Title, Year, Season, DateR, Img, Synopsis, Link1, Link2";
            if ($link3Exists) {
                $selectFields .= ", Link3";
            }

            if(isset($_POST['Sbtn'])) {
                $year = $con2->real_escape_string($_POST['AniYear']);
                $season = $con2->real_escape_string($_POST['AniSeason']);
                
                $week = $con2->real_escape_string($_POST['AniWeek']);
                $sql = "SELECT $selectFields FROM main_db 
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
                            $date1 = Date($input);
                            $date2 = Date('Y-m-d'); 
                        } else {
                            $StDate="No Information";
                        }

                        echo '<div class="anime-item">
                                <img class="anime-poster" src="'.$row['Img'].'" alt="'.$row['Title'].'">
                                <div class="anime-info">
                                    <h2 class="anime-title" onclick="editAnime('.$row['Rec_Num'].')">'.$row['Title'].'</h2>
                                    <div class="anime-meta">'.$row['Season'].' '.$row['Year'].' | Released: '.$StDate.'</div>
                                    <div class="anime-actions">';

                        if($date1 > $date2) {
                            echo '<span class="Unrel"> Unreleased </span>';
                        } else {
                            echo '<p style="font-size:13px; ">Watch on: </p>';
                            // Show Link1 button only if Link1 is not empty
                            if (!empty($row['Link1'])) {
                                echo '<button class="lnk-btn1" onclick="event.stopPropagation(); window.open(\''.$row['Link1'].'\', \'_blank\')">HiAnime</button>';
                            }
                            
                            // Show Link2 button only if Link2 is not empty
                            if (!empty($row['Link2'])) {
                                echo '<button class="lnk-btn2" onclick="event.stopPropagation(); window.open(\''.$row['Link2'].'\', \'_blank\')">AnimeNoSub</button>';
                            }

                            // Show Link3 button only if Link3 exists and is not empty
                            if ($link3Exists && !empty($row['Link3'])) {
                                echo '<button class="lnk-btn3" onclick="event.stopPropagation(); window.open(\''.$row['Link3'].'\', \'_blank\')">Youtube</button>';
                            }
                        }

                        echo '</div>
                                    <div class="S-Div">'.($row['Synopsis'] ?: 'No synopsis available').'</div>
                                </div>
                              </div>';
                    }
                } else {
                    echo '<div class="no-results">No anime found for '.$season.' '.$year.'</div>';
                }
            }

            if(isset($_POST['Ebtn'])) {
                $today = date('Y-m-d');
                $currentSeason = $var;
                
                $sql = "SELECT $selectFields FROM main_db 
                        WHERE Season='$currentSeason' AND YEAR(DateR)=YEAR('$today') 
                        AND DAYNAME(DateR) = DAYNAME('$today')";
                
                $result = $con2->query($sql);
                
                if ($result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        if($row['DateR']!='') {
                            $input = $row['DateR'];
                            $date = strtotime($input);
                            $StDate=date('M d, l', $date);
                            $date1 = Date($input);
                            $date2 = Date('Y-m-d'); 
                            echo '<div class="anime-item">
                                    <img class="anime-poster" src="'.$row['Img'].'" alt="'.$row['Title'].'">
                                    <div class="anime-info">
                                        <h2 class="anime-title" onclick="editAnime('.$row['Rec_Num'].')">'.$row['Title'].'</h2>
                                        <div class="anime-meta">'.$row['Season'].' '.$row['Year'].' | Released: '.$StDate.'</div>
                                        <div class="anime-actions">';

                            if($date1 > $date2) {
                                echo '<span class="Unrel"> Unreleased </span>';
                            } else {
                                echo '<p style="font-size:13px; ">Watch on: </p>';
                                // Show Link1 button only if Link1 is not empty
                                if (!empty($row['Link1'])) {
                                    echo '<button class="lnk-btn1" onclick="event.stopPropagation(); window.open(\''.$row['Link1'].'\', \'_blank\')">HiAnime</button>';
                                }
                                
                                // Show Link2 button only if Link2 is not empty
                                if (!empty($row['Link2'])) {
                                    echo '<button class="lnk-btn2" onclick="event.stopPropagation(); window.open(\''.$row['Link2'].'\', \'_blank\')">AnimeNoSub</button>';
                                }

                                // Show Link3 button only if Link3 exists and is not empty
                                if ($link3Exists && !empty($row['Link3'])) {
                                    echo '<button class="lnk-btn3" onclick="event.stopPropagation(); window.open(\''.$row['Link3'].'\', \'_blank\')">Youtube</button>';
                                }
                            }

                            echo '</div>
                                        <div class="S-Div">'.($row['Synopsis'] ?: 'No synopsis available').'</div>
                                    </div>
                                  </div>';
                        }
                    }
                } else {
                    echo '<div class="no-results">No new episodes today for '.$currentSeason.' season</div>';
                }
            }

            if(isset($_POST['Abtn'])) {
                $sql = "SELECT $selectFields FROM main_db ORDER BY Year DESC, Season DESC";
                $result = $con2->query($sql);

                if ($result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        if($row['DateR']!='') {
                            $input = $row['DateR'];
                            $date = strtotime($input);
                            $StDate=date('M d, l', $date);
                            $date1 = Date($input);
                            $date2 = Date('Y-m-d'); 
                        } else {
                            $StDate="No Information";
                        }

                        echo '<div class="anime-item">
                                <img class="anime-poster" src="'.$row['Img'].'" alt="'.$row['Title'].'">
                                <div class="anime-info">
                                    <h2 class="anime-title" onclick="editAnime('.$row['Rec_Num'].')">'.$row['Title'].'</h2>
                                    <div class="anime-meta">'.$row['Season'].' '.$row['Year'].' | Released: '.$StDate.'</div>
                                    <div class="anime-actions">';

                        if($date1 > $date2) {
                            echo '<span class="Unrel"> Unreleased </span>';
                        } else {
                            echo '<p style="font-size:13px; ">Watch on: </p>';
                            // Show Link1 button only if Link1 is not empty
                            if (!empty($row['Link1'])) {
                                echo '<button class="lnk-btn1" onclick="event.stopPropagation(); window.open(\''.$row['Link1'].'\', \'_blank\')">HiAnime</button>';
                            }
                            
                            // Show Link2 button only if Link2 is not empty
                            if (!empty($row['Link2'])) {
                                echo '<button class="lnk-btn2" onclick="event.stopPropagation(); window.open(\''.$row['Link2'].'\', \'_blank\')">AnimeNoSub</button>';
                            }

                            // Show Link3 button only if Link3 exists and is not empty
                            if ($link3Exists && !empty($row['Link3'])) {
                                echo '<button class="lnk-btn3" onclick="event.stopPropagation(); window.open(\''.$row['Link3'].'\', \'_blank\')">Youtube</button>';
                            }
                        }

                        echo '</div>
                                    <div class="S-Div">'.($row['Synopsis'] ?: 'No synopsis available').'</div>
                                </div>
                              </div>';
                    }
                } else {
                    echo '<div class="no-results">No anime found in database</div>';
                }
            }
            ?>
        </div>

        <div id="add-section" class="card add-card hidden">
            <div class="upload-instructions">
                <center><h2>Add Anime</h2></center>
            </div>
            <br>
            <table>
                <tr>
                    <td>Title</td>
                    <td>Date</td>
                    <td>Season</td>
                    <td></td>
                </tr>
                <tr>
                    <td>
                        <input type="text" minlength="1" maxlength="250" 
                               name="AnimeTitle" id="AnimeTitle" required 
                               placeholder="Anime title">
                    </td>
                    <td>
                        <input type="date" id="AnimeDate" name="AnimeDate" 
                               onChange="SeasonChange()" value="<?php echo date('Y-m-d'); ?>">
                        <input type="hidden" id="AnimeYear" name="AnimeYear" 
                               value="<?php echo date('Y'); ?>">
                    </td>
                    <td>
                        <select name="AnimeSeason" id="AnimeSeason">
                            <option value="Summer">Summer</option>
                            <option value="Fall">Fall</option>
                            <option value="Winter">Winter</option>
                            <option value="Spring">Spring</option>
                        </select>
                    </td>
                    <td>
                        <button class="btn" id="uploadBtn" onclick="uploadImage(event)">Upload</button>
                    </td>
                </tr>
            </table>
            
            <div class="upload-options">
                <div class="file-upload-container">
                    <label class="file-input-label">
                        Select Image File
                        <input type="file" id="fileInput" accept="image/*" style="display: none">
                    </label>
                    <span id="fileName">Select file or paste image from clipboard</span>
                </div>
                <div id="fileError" style="color: red; display: none;"></div>
            </div>
            
            <div id="result">
                <div id="imagePreviewContainer" style="margin-top: 15px; text-align: center;"></div>
            </div>
        </div>
    </div>

    <div id="editModal" class="edit-form hidden">
        <h3>Edit Anime</h3>
        <form id="editForm">
            <input type="hidden" id="edit_id" name="id">
            <table>
                <tr>
                    <td>Title:</td>
                    <td><input type="text" id="edit_title" name="title" required></td>
                </tr>
                <tr hidden>
                    <td>Year:</td>
                    <td><input type="number" id="edit_year" name="year" required></td>
                </tr>
                <tr hidden>
                    <td>Season:</td>
                    <td>
                        <select id="edit_season" name="season" required>
                            <option value="Summer">Summer</option>
                            <option value="Fall">Fall</option>
                            <option value="Winter">Winter</option>
                            <option value="Spring">Spring</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Release Date:</td>
                    <td><input type="date" id="edit_date" name="date" required onchange="updateSeasonYear()"></td>
                </tr>
                <tr>
                    <td>Synopsis:</td>
                    <td><textarea id="edit_synopsis" name="synopsis" rows="4" placeholder="Enter anime synopsis"></textarea></td>
                </tr>
                <tr>
                    <td>HiAnime:</td>
                    <td><input type="url" id="edit_link1" name="link1" placeholder="https://HiAnime.com"></td>
                </tr>
                <tr>
                    <td>AnimeNoSub:</td>
                    <td><input type="url" id="edit_link2" name="link2" placeholder="https://AnimeNoSub.com"></td>
                </tr>
                <tr>
                    <td>Youtube:</td>
                    <td><input type="url" id="edit_link3" name="link3" placeholder="https://Youtube.com"></td>
                </tr>
            </table>
            <div class="form-actions">
                <button type="button" class="cancelbtn" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="savebtn">Save Changes</button>
            </div>
            <div class="delete-action">
                <button type="button" class="delete-btn" onclick="deleteAnime()">🗑 Delete</button>
            </div>
        </form>
    </div>

    <script>
        // Initialize the page
        document.getElementById('search-section').classList.add('hidden');
        document.getElementById('add-section').classList.add('hidden');
        document.getElementById('AnimeSeason').disabled = true;
        document.getElementById('AniSeason').value="<?php echo $var; ?>";
        document.getElementById('AnimeSeason').value="<?php echo $var; ?>";

        // Store the selected file
        let selectedFile = null;
        
        function navigateToHome() {
            window.location.href = 'index.php';
        }

        function btnSearch() {
            document.getElementById('add-section').classList.add('hidden');
            document.getElementById('search-section').classList.remove('hidden');
            document.getElementById('resDiv').style.display = 'flex';
        }

        function btnUpload() {
            document.getElementById('search-section').classList.add('hidden');
            document.getElementById('add-section').classList.remove('hidden');
            document.getElementById('resDiv').style.display = 'none';
            document.getElementById('AnimeTitle').focus();
        }

        function SeasonChange() {
            const dateInput = new Date(document.getElementById('AnimeDate').value);
            const yearHolder = dateInput.getFullYear();
            const monthHolder = dateInput.getMonth();

            document.getElementById('AnimeYear').value = yearHolder;

            if (monthHolder >= 2 && monthHolder <= 4) {
                document.getElementById('AnimeSeason').value = "Spring";
            } else if (monthHolder >= 5 && monthHolder <= 7) {
                document.getElementById('AnimeSeason').value = "Summer";
            } else if (monthHolder >= 8 && monthHolder <= 10) {
                document.getElementById('AnimeSeason').value = "Fall";
            } else {
                document.getElementById('AnimeSeason').value = "Winter";
            }
        }

        // Function to update season and year based on date in edit form
        function updateSeasonYear() {
            const dateInput = new Date(document.getElementById('edit_date').value);
            const yearHolder = dateInput.getFullYear();
            const monthHolder = dateInput.getMonth();

            document.getElementById('edit_year').value = yearHolder;

            if (monthHolder >= 2 && monthHolder <= 4) {
                document.getElementById('edit_season').value = "Spring";
            } else if (monthHolder >= 5 && monthHolder <= 7) {
                document.getElementById('edit_season').value = "Summer";
            } else if (monthHolder >= 8 && monthHolder <= 10) {
                document.getElementById('edit_season').value = "Fall";
            } else {
                document.getElementById('edit_season').value = "Winter";
            }
        }

        // New helper function to show the image preview
        function previewImage(file) {
            const previewContainer = document.getElementById('imagePreviewContainer');
            previewContainer.innerHTML = ''; // Clear previous preview

            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    // Added styling to limit image size
                    img.style.maxWidth = '100%'; 
                    img.style.maxHeight = '300px'; 
                    img.style.borderRadius = '10px';
                    img.style.boxShadow = '0 4px 8px rgba(0, 0, 0, 0.2)';
                    previewContainer.appendChild(img);
                };
                reader.readAsDataURL(file);
            }
        }

        // File input handling
        document.getElementById('fileInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                selectedFile = file;
                document.getElementById('fileName').textContent = file.name;
                document.getElementById('fileError').style.display = 'none';
                previewImage(file); // Call preview function
            }
        });

        // Paste from clipboard
        document.addEventListener('paste', function(e) {
            const items = e.clipboardData?.items;
            if (!items) return;

            for (let item of items) {
                if (item.type.startsWith('image/')) {
                    e.preventDefault();
                    const file = item.getAsFile();
                    if (file) {
                        selectedFile = file;
                        document.getElementById('fileName').textContent = 'Pasted image: ' + file.name;
                        document.getElementById('fileError').style.display = 'none';
                        previewImage(file); // Call preview function
                    }
                    break;
                }
            }
        });

        async function uploadImage(event) {
            event.preventDefault();
            
            const title = document.getElementById('AnimeTitle').value.trim();
            const year = document.getElementById('AnimeYear').value;
            const season = document.getElementById('AnimeSeason').value;
            const date = document.getElementById('AnimeDate').value;

            if (!title) {
                Swal.fire({ icon: 'warning', title: 'Missing Title', text: 'Please enter an anime title.' });
                return;
            }

            if (!selectedFile) {
                Swal.fire({ icon: 'warning', title: 'No Image', text: 'Please select an image file or paste an image.' });
                return;
            }

            const uploadBtn = document.getElementById('uploadBtn');
            uploadBtn.disabled = true;
            uploadBtn.textContent = 'Uploading...';

            try {
                // Upload image first
                const formData = new FormData();
                formData.append('image', selectedFile);

                const uploadResponse = await fetch('?action=image_upload&for=anime', {
                    method: 'POST',
                    body: formData
                });

                const uploadResult = await uploadResponse.json();

                if (!uploadResult.success) {
                    throw new Error(uploadResult.message || 'Image upload failed');
                }

                // Save anime data to database
                const saveResponse = await fetch('?action=db_connection&for=anime', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        title: title,
                        year: year,
                        season: season,
                        image: uploadResult.filename,
                        date: date,
                        synopsis: '',
                        link1: '',
                        link2: '',
                        link3: ''
                    })
                });

                const saveResult = await saveResponse.json();

                if (saveResult.success) {
                    // show success and then reload so user sees animation
                    Swal.fire({
                        icon: 'success',
                        title: 'Added',
                        text: 'Anime added successfully!',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    throw new Error(saveResult.message || 'Failed to save anime data');
                }

            } catch (error) {
                console.error('Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: error.message });
            } finally {
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Upload';
            }
        }

        // Login Modal Functions
        function showLoginModal() {
            document.getElementById('loginModal').classList.remove('hidden');
        }

        function closeLoginModal() {
            document.getElementById('loginModal').classList.add('hidden');
        }

        // Handle login form submission
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const username = document.getElementById('login_username').value;
            const password = document.getElementById('login_password').value;

            try {
                const response = await fetch('?action=login&for=anime', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        username: username,
                        password: password
                    })
                });

                const result = await response.json();

                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: 'Login successful!',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        closeLoginModal();
                        location.reload();
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Login Failed', text: result.message });
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Login failed: ' + error.message });
            }
        });

        // Logout function
        async function logout() {
            try {
                const response = await fetch('?action=logout&for=anime');
                const result = await response.json();

                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Logged Out',
                        text: 'You have been logged out successfully!',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                }
            } catch (error) {
                console.error('Error:', error);
                location.reload(); // Force reload even if fetch fails
            }
        }

        // Edit anime functions
        async function editAnime(id) {
            // Check if user is logged in
            <?php if (!isset($_SESSION['login']) || $_SESSION['login'] !== true): ?>
            showLoginModal();
            return;
            <?php endif; ?>

            try {
                const response = await fetch(`?action=get_anime&for=anime&id=${id}`);
                const result = await response.json();
                
                if (result.success) {
                    const anime = result.anime;
                    
                    // Populate edit form
                    document.getElementById('edit_id').value = anime.Rec_Num;
                    document.getElementById('edit_title').value = anime.Title;
                    document.getElementById('edit_year').value = anime.Year;
                    document.getElementById('edit_season').value = anime.Season;
                    document.getElementById('edit_date').value = anime.DateR;
                    document.getElementById('edit_synopsis').value = anime.Synopsis || '';
                    document.getElementById('edit_link1').value = anime.Link1 || '';
                    document.getElementById('edit_link2').value = anime.Link2 || '';
                    document.getElementById('edit_link3').value = anime.Link3 || '';
                    
                    // Show edit modal
                    document.getElementById('editModal').classList.remove('hidden');
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message });
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Error loading anime data' });
            }
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        // Handle edit form submission
        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            try {
                const response = await fetch('?action=update_anime&for=anime', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: document.getElementById('edit_id').value,
                        title: document.getElementById('edit_title').value,
                        year: document.getElementById('edit_year').value,
                        season: document.getElementById('edit_season').value,
                        date: document.getElementById('edit_date').value,
                        synopsis: document.getElementById('edit_synopsis').value,
                        link1: document.getElementById('edit_link1').value,
                        link2: document.getElementById('edit_link2').value,
                        link3: document.getElementById('edit_link3').value
                    })
                });

                const result = await response.json();

                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated',
                        text: 'Anime updated successfully!',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        closeEditModal();
                        location.reload();
                    });
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Error updating anime: ' + error.message });
            }
        });

        async function deleteAnime() {
            const id = document.getElementById('edit_id').value;
            
            const confirmDelete = await Swal.fire({
                title: 'Are you sure?',
                text: 'This will permanently delete the anime.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            });
            if (!confirmDelete.isConfirmed) {
                return;
            }

            try {
                const response = await fetch('?action=delete_anime&for=anime', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: id })
                });

                const result = await response.json();

                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted',
                        text: 'Anime deleted successfully!',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        closeEditModal();
                        location.reload();
                    });
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Error deleting anime: ' + error.message });
            }
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('editModal');
            if (event.target === modal) {
                closeEditModal();
            }
            
            const loginModal = document.getElementById('loginModal');
            if (event.target === loginModal) {
                closeLoginModal();
            }
        });
    </script>
</body>
</html>
<?php
$con2->close();
?>