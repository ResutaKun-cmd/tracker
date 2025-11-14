<?php
// Unified Database Configuration
$db_config = [
    'anime' => [
        'servername' => "localhost",
        'username' => "root",
        'password' => "",
        'port' => "3306",
        'database' => "anime"
    ]
];

// Determine which app to show
$app = isset($_GET['app']) ? $_GET['app'] : 'anime';

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

    try {
        $stmt = $conn->prepare("INSERT INTO main_db (Title, Year, Season, Img, DateR) VALUES (?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("sisss", 
            $title,
            $year,
            $season,
            $image,
            $date
        );
        
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

// Handle Anime Update
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
    $title = $conn->real_escape_string($data['title']);
    $year = (int)$data['year'];
    $season = $conn->real_escape_string($data['season']);
    $date = $conn->real_escape_string($data['date']);
    $synopsis = isset($data['synopsis']) ? $conn->real_escape_string($data['synopsis']) : null;

    try {
        $sets = [];
        $sets[] = "Title = '$title'";
        $sets[] = "Year = $year";
        $sets[] = "Season = '$season'";
        $sets[] = "DateR = '$date'";
        if ($synopsis !== null) {
            $sets[] = "Synopsis = '$synopsis'";
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

// ANIME TRACKER SYSTEM
function renderAnimeTracker($config) {
    $con2 = new mysqli(
        $config['servername'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port'] ?? 3306
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

            input, select {
                padding: 10px;
                width: 100%;
                border-radius: 5px;
                border: 1px solid #ddd;
                background-color: rgba(255, 255, 255, 0.9);
            }

            input:focus, select:focus {
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

            .edit-form input, .edit-form select {
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
            .S-Div
            {
                margin-top: 20px;
                width: 300px;
                word-wrap: break-word;
            }
        </style>
    </head>
    <body class="anime-app">
        <div class="container">
            <div class="app-navigation">
                <button onclick="navigateTo('anime')">Anime Tracker</button>
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
                                       value="<?php echo $Y; ?>" name="AniYear">
                            </td>
                            <td>
                                <select name="AniSeason">
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
$sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img FROM main_db 
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
                                        <div class="S-Div"></div>
                                    </div>
                                  </div>';
                        }
                    } else {
                        echo '<div class="no-results">No anime found for '.$season.' '.$year.'</div>';
                    }
                }

                if(isset($_POST['Ebtn'])) {
                    $tdy=date('Y');
                    $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img FROM main_db WHERE Year='$tdy' or Season='$var'";
                    $result = $con2->query($sql);
                    
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            if($row['DateR']!='') {
                                $input = $row['DateR'];
                                $date = strtotime($input);
                                $StDate=date('l', $date);
                                $TdDate=date('l');
                            
                                if ($TdDate==$StDate) {
                                    echo '<div class="anime-item">
                                            <img class="anime-poster" src="'.$row['Img'].'" alt="'.$row['Title'].'">
                                            <div class="anime-info">
                                                <h2 class="anime-title">'.$row['Title'].'</h2>
                                                <div class="anime-meta">'.$row['Season'].' '.$row['Year'].' | Released: '.$StDate.'</div>
                                                <div class="anime-actions">
                                                    <button class="edit-btn" onclick="editAnime('.$row['Rec_Num'].')">Edit</button>
                                                    <button class="delete-btn" onclick="deleteAnime('.$row['Rec_Num'].')">Delete</button>
                                                </div>
                                            </div>
                                          </div>';
                                }
                            }
                        }
                    } else {
                        echo '<div class="no-results">No new episodes today</div>';
                    }
                }

                if(isset($_POST['Abtn'])) {
                    $year = $con2->real_escape_string($_POST['AniYear']);
                    $season = $con2->real_escape_string($_POST['AniSeason']);
                    
                    $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img FROM main_db";
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
                                    </div>
                                  </div>';
                        }
                    } else {
                        echo '<div class="no-results">No anime found in database</div>';
                    }
                }
                ?>
            </div>

            <!-- Add Anime Section -->
            <div id="add-section" class="card add-card hidden">
                <div class="upload-instructions">
                    <center>  <h2>Add Anime</h2> </center>
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
                        <span id="fileName">Upload image via clipboard or slect file</span>
                    </div>
                    <div id="fileError" style="color: red; display: none;"></div>
                </div>
                
                <div id="result"></div>
            </div>
        </div>

        <script>
            document.getElementById('search-section').classList.add('hidden');
            document.getElementById('add-section').classList.add('hidden');
            document.getElementById('AnimeSeason').disabled = true;

            // Store the selected file
            let selectedFile = null;
            
            function navigateTo(app) {
                window.location.href = `?app=${app}`;
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
                    document.getElementById('AnimeSeason').value = 'Spring';
                } else if (monthHolder >= 5 && monthHolder <= 7) {
                    document.getElementById('AnimeSeason').value = 'Summer';
                } else if (monthHolder >= 8 && monthHolder <= 10) {
                    document.getElementById('AnimeSeason').value = 'Fall';
                } else {
                    document.getElementById('AnimeSeason').value = 'Winter';
                }
            }

            // Handle file selection
            document.getElementById('fileInput').addEventListener('change', function(event) {
                selectedFile = event.target.files[0];
                document.getElementById('fileName').textContent = selectedFile ? selectedFile.name : 'No file selected';
                document.getElementById('fileError').style.display = 'none';
            });

            // Handle paste from clipboard
            document.addEventListener('paste', function(e) {
                if (document.getElementById('add-section').classList.contains('hidden')) return;
                
                const items = e.clipboardData.items;
                for (let i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image') !== -1) {
                        const blob = items[i].getAsFile();
                        selectedFile = blob;
                        document.getElementById('fileName').textContent = 'Clipboard image ready';
                        document.getElementById('fileError').style.display = 'none';
                        e.preventDefault();
                        break;
                    }
                }
            });

            // Upload image to server
            function uploadImage(event) {
                event.preventDefault();
                
                const title = document.getElementById('AnimeTitle').value.trim();
                const year = document.getElementById('AnimeYear').value;
                const season = document.getElementById('AnimeSeason').value;
                const date = document.getElementById('AnimeDate').value;
                
                if (!title) {
                    alert('Please enter an anime title');
                    return;
                }
                
                if (!selectedFile) {
                    alert('Please select an image file or paste an image from clipboard');
                    return;
                }

                const formData = new FormData();
                formData.append('image', selectedFile);
                
                fetch('?action=image_upload&for=anime', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('result').innerHTML = `<img src="${data.filename}" alt="Uploaded image">`;
                        
                        // Save to database
                        const animeData = {
                            title: title,
                            year: year,
                            season: season,
                            image: data.filename,
                            date: date
                        };
                        
                        fetch('?action=db_connection&for=anime', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(animeData)
                        })
                        .then(response => response.json())
                        .then(dbData => {
                            if (dbData.success) {
                                alert('Anime added successfully!');
                                document.getElementById('AnimeTitle').value = '';
                                document.getElementById('result').innerHTML = '';
                                document.getElementById('fileName').textContent = 'Upload image via clipboard or select file';
                                selectedFile = null;
                                document.getElementById('fileInput').value = '';
                            } else {
                                alert('Error saving to database: ' + dbData.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error saving to database');
                        });
                    } else {
                        alert('Upload failed: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Upload failed');
                });
            }

            // Delete anime function
            function deleteAnime(id) {
                if (confirm('Are you sure you want to delete this anime?')) {
                    fetch('?action=delete_anime&for=anime', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ id: id })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Anime deleted successfully');
                            location.reload();
                        } else {
                            alert('Error deleting anime: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error deleting anime');
                    });
                }
            }

            // Edit anime function
            function editAnime(id) {
                fetch(`?action=get_anime&for=anime&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const anime = data.anime;
                        
                        // Create edit form
                        const form = document.createElement('div');
                        form.className = 'edit-form';
                        form.innerHTML = `
                            <h3>Edit Anime</h3>
                            <table>
                                <tr>
                                    <td>Title:</td>
                                    <td><input type="text" id="editTitle" value="${anime.Title}"></td>
                                </tr>
                                <tr>
                                    <td>Year:</td>
                                    <td><input type="number" id="editYear" value="${anime.Year}"></td>
                                </tr>
                                <tr>
                                    <td>Season:</td>
                                    <td>
                                        <select id="editSeason">
                                            <option value="Summer" ${anime.Season === 'Summer' ? 'selected' : ''}>Summer</option>
                                            <option value="Fall" ${anime.Season === 'Fall' ? 'selected' : ''}>Fall</option>
                                            <option value="Winter" ${anime.Season === 'Winter' ? 'selected' : ''}>Winter</option>
                                            <option value="Spring" ${anime.Season === 'Spring' ? 'selected' : ''}>Spring</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Release Date:</td>
                                    <td><input type="date" id="editDate" value="${anime.DateR}"></td>
                                </tr>
                            </table>
                            <div class="form-actions">
                                <button onclick="saveEdit(${id})">Save</button>
                                <button onclick="closeEditForm()">Cancel</button>
                            </div>
                        `;
                        
                        document.body.appendChild(form);
                    } else {
                        alert('Error loading anime: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading anime');
                });
            }

            function saveEdit(id) {
                const title = document.getElementById('editTitle').value;
                const year = document.getElementById('editYear').value;
                const season = document.getElementById('editSeason').value;
                const date = document.getElementById('editDate').value;
                
                fetch('?action=update_anime&for=anime', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        title: title,
                        year: year,
                        season: season,
                        date: date
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Anime updated successfully');
                        closeEditForm();
                        location.reload();
                    } else {
                        alert('Error updating anime: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating anime');
                });
            }

            function closeEditForm() {
                const form = document.querySelector('.edit-form');
                if (form) {
                    form.remove();
                }
            }
        </script>
    </body>
    </html>
    <?php
    $con2->close();
}

// Render the appropriate app
if ($app === 'anime') {
    renderAnimeTracker($db_config['anime']);
}
?>