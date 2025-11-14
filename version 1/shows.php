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

// Determine which app to show
$app = 'shows';

// Handle Image Uploads
if (isset($_GET['action']) && $_GET['action'] === 'image_upload') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'shows';
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

// SHOW TRACKER SYSTEM
$conn = new mysqli(
    $db_config['shows']['servername'],
    $db_config['shows']['username'],
    $db_config['shows']['password'],
    $db_config['shows']['database']
);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create table if it doesn't exist with type column
$conn->query("CREATE TABLE IF NOT EXISTS shows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    type ENUM('k_drama', 'anime', 'american_series', 'movie') NOT NULL,
    status ENUM('unwatched', 'ongoing', 'watched') NOT NULL,
    image_path VARCHAR(255),
    synopsis TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_show'])) {
        $title = $conn->real_escape_string($_POST['title']);
        $type = $conn->real_escape_string($_POST['type']);

        if (isset($_POST['image_data'])) {
            $imageData = $_POST['image_data'];
            $imageData = str_replace('data:image/png;base64,', '', $imageData);
            $imageData = str_replace(' ', '+', $imageData);
            $imageBinary = base64_decode($imageData);

            $uploadsDir = 'uploads/shows/';
            if (!file_exists($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }

            $filename = uniqid() . '.png';
            $filepath = $uploadsDir . $filename;

            if (file_put_contents($filepath, $imageBinary)) {
                $sql = "INSERT INTO shows (title, type, status, image_path) VALUES ('$title', '$type', 'unwatched', '$filepath')";
                if ($conn->query($sql)) {
                    echo json_encode(['success' => true, 'message' => 'Show added successfully!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save image']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No image data received']);
        }
        exit;
    } elseif (isset($_POST['update_status'])) {
        $id = intval($_POST['id']);
        $status = $conn->real_escape_string($_POST['status']);

        $sql = "UPDATE shows SET status = '$status' WHERE id = $id";
        if ($conn->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    } elseif (isset($_POST['delete_show'])) {
        $id = intval($_POST['id']);
        $sql = "SELECT image_path FROM shows WHERE id = $id";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (file_exists($row['image_path'])) {
                unlink($row['image_path']);
            }
        }

        $sql = "DELETE FROM shows WHERE id = $id";
        if ($conn->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    } elseif (isset($_POST['update_synopsis'])) {
        $id = intval($_POST['id']);
        $synopsis = $conn->real_escape_string($_POST['synopsis']);
        
        $sql = "UPDATE shows SET synopsis = '$synopsis' WHERE id = $id";
        if ($conn->query($sql)) {
            echo json_encode(['success' => true, 'message' => 'Synopsis updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit;
    }
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recent';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Build SQL query based on filters
$sql = "SELECT * FROM shows";
$where = [];
if ($filter !== 'all') {
    $where[] = "status = '" . $conn->real_escape_string($filter) . "'";
}
if ($type_filter !== 'all') {
    $where[] = "type = '" . $conn->real_escape_string($type_filter) . "'";
}
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

// Add sorting
switch ($sort) {
    case 'title_asc':
        $sql .= " ORDER BY title ASC";
        break;
    case 'title_desc':
        $sql .= " ORDER BY title DESC";
        break;
    case 'status':
        $sql .= " ORDER BY FIELD(status, 'unwatched', 'ongoing', 'watched')";
        break;
    case 'recent':
    default:
        $sql .= " ORDER BY id DESC";
        break;
}

$result = $conn->query($sql);
$shows = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $shows[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Show Tracker</title>
    <style>
        :root {
            --dark-bg: #1a1a1a;
            --card-bg: #2d2d2d;
            --text-color: #e0e0e0;
            --accent-color: #4a8fe7;
            --watched-color: #4CAF50;
            --unwatched-color: #f44336;
            --ongoing-color: #FF9800;
            --k-drama-color: #e84393;
            --anime-color: #0984e3;
            --american-series-color: #00b894;
            --movie-color: #fdcb6e;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body.shows-app {
            background-color: var(--dark-bg);
            color: var(--text-color);
            padding: 20px;
            position: relative;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px 0;
            border-bottom: 1px solid #444;
        }
        
        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: var(--accent-color);
        }
        
        .add-show-form {
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: 0px 8px 8px 8px;
            margin-bottom: 30px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        input, select {
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #444;
            background-color: #333;
            color: var(--text-color);
        }
        
        button {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .add-btn {
            background-color: var(--accent-color);
            color: white;
        }
        
        .add-btn:hover {
            background-color: #3a7bd5;
        }
        
        .shows-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .show-card {
            background-color: var(--card-bg);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            position: relative;
        }
        
        .show-image {
            width: 100%;
            height: 550px;
            object-fit: cover;
            cursor: pointer;
        }
        
        .show-info {
            padding: 15px;
        }
        
        .show-title {
            font-size: 1.2rem;
            margin-bottom: 5px;
            color: var(--accent-color);
            cursor: pointer;
        }
        
        .show-title:hover {
            text-decoration: underline;
        }
        
        .show-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
            color: #aaa;
        }
        
        .show-type {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .show-type.k_drama {
            background-color: var(--k-drama-color);
            color: white;
        }
        
        .show-type.anime {
            background-color: var(--anime-color);
            color: white;
        }
        
        .show-type.american_series {
            background-color: var(--american-series-color);
            color: white;
        }
        
        .show-type.movie {
            background-color: var(--movie-color);
            color: black;
        }
        
        .show-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        
        .status-btn {
            flex-grow: 1;
            margin-right: 10px;
            color: white;
        }
        
        .watched {
            background-color: var(--watched-color);
        }
        
        .watched:hover {
            background-color: #3e8e41;
        }
        
        .unwatched {
            background-color: var(--unwatched-color);
        }
        
        .unwatched:hover {
            background-color: #d32f2f;
        }
        
        .ongoing {
            background-color: var(--ongoing-color);
        }
        
        .ongoing:hover {
            background-color: #e68a00;
        }
        
        .delete-btn {
            background-color: #333;
            color: var(--text-color);
        }
        
        .delete-btn:hover {
            background-color: #444;
        }
        
        .no-shows {
            text-align: center;
            padding: 40px;
            font-size: 1.2rem;
            color: #777;
        }
        
        .status-message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            text-align: center;
        }
        
        .success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--watched-color);
            display: none;
        }
        
        .error {
            background-color: rgba(244, 67, 54, 0.2);
            color: var(--unwatched-color);
            display: none;
        }
        
        .clipboard-instructions {
            margin-top: 15px;
            padding: 10px;
            background-color: rgba(74, 143, 231, 0.1);
            border-left: 3px solid var(--accent-color);
            font-size: 0.9rem;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            background-color: #444;
            color: var(--text-color);
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .filter-btn.active {
            background-color: var(--accent-color);
        }

        .sort-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .sort-options {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sort-dropdown {
            padding: 8px 12px;
            border-radius: 4px;
            background-color: #444;
            color: white;
            border: 1px solid #666;
        }

        .search-box {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .search-input {
            padding: 8px 35px 8px 10px;
            border-radius: 4px;
            border: 1px solid #444;
            background-color: #333;
            color: var(--text-color);
            font-size: 0.9rem;
            width: 200px;
        }
        
        .search-icon {
            position: absolute;
            right: 10px;
            color: #777;
        }
        
        .hidden {
            display: none;
        }

        .combined-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background-color: var(--accent-color);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }
        
        .back-to-top.show {
            opacity: 1;
            visibility: visible;
        }
        
        .back-to-top:hover {
            background-color: #3a7bd5;
            transform: translateY(-3px);
        }

        .btnAdd {
            background-color: var(--card-bg);
            color: white;
            border-radius: 4px 4px 0px 0px;
        }
        
        /* File Upload Styles */
        .file-upload-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
        }
        
        .file-input-label {
            padding: 8px 15px;
            background-color: var(--accent-color);
            color: white;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
            text-align: center;
        }
        
        .file-input-label:hover {
            background-color: #3a7bd5;
        }
        
        #fileError {
            color: #f44336;
            margin-top: 5px;
            font-size: 0.9rem;
            display: none;
        }

        .type-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .type-btn {
            background-color: #444;
            color: var(--text-color);
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .type-btn.active.k_drama {
            background-color: var(--k-drama-color);
        }
        
        .type-btn.active.anime {
            background-color: var(--anime-color);
        }
        
        .type-btn.active.american_series {
            background-color: var(--american-series-color);
        }
        
        .type-btn.active.movie {
            background-color: var(--movie-color);
            color: black;
        }

        @media (max-width: 768px) {
            .combined-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-input {
                width: 100%;
            }
            
            .back-to-top {
                bottom: 20px;
                right: 20px;
                width: 45px;
                height: 45px;
                font-size: 1.3rem;
            }
        }
        
        .app-navigation {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .app-navigation button {
            background-color: #ff6b6b;
            width: auto;
        }
        
        .show-synopsis {
            display: none;
        }
        
        /* Modal Styles */
        .show-modal-backdrop { 
            position: fixed; 
            inset:0; 
            display:none; 
            align-items:center; 
            justify-content:center; 
            background: rgba(0,0,0,0.6); 
            z-index: 9999; 
        }
        
        .show-modal { 
            width: min(900px, 96vw); 
            background: #0f1520; 
            border-radius:12px; 
            display:grid; 
            grid-template-columns: 300px 1fr; 
            overflow:hidden; 
            border:1px solid #243249; 
        }
        
        .show-modal .left { 
            padding:16px; 
            display:flex; 
            align-items:center; 
            justify-content:center; 
            background:#081018; 
        }
        
        .show-modal .left img { 
            width:100%; 
            height:360px; 
            object-fit:cover; 
            border-radius:8px; 
        }
        
        .show-modal .right { 
            padding:16px; 
            border-left:1px solid #1b2537; 
        }
        
        .show-modal .close-btn { 
            background:transparent; 
            border:none; 
            color:#a9c5ee; 
            font-size:22px; 
            cursor:pointer; 
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }
        
        .show-modal h3 {
            margin-bottom: 15px;
            color: var(--accent-color);
        }
        
        .show-modal .synopsis-container {
            margin-top: 15px;
        }
        
        .show-modal .synopsis-text {
            background:#07101b; 
            color:#e6eefc; 
            border-radius:8px; 
            border:1px solid #1b2435; 
            padding:10px;
            min-height: 150px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .show-modal .edit-btn {
            background-color: var(--accent-color);
            color: white;
            margin-top: 10px;
            padding: 8px 12px;
        }
        
        .show-modal .edit-synopsis {
            display: none;
            margin-top: 15px;
        }
        
        .show-modal .edit-synopsis textarea {
            width: 100%;
            min-height: 150px;
            background:#07101b; 
            color:#e6eefc; 
            border-radius:8px; 
            border:1px solid #1b2435; 
            padding:10px;
            resize: vertical;
        }
        
        .show-modal .edit-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
        }
        
        .show-modal .edit-actions button {
            padding: 8px 12px;
        }
        
        .hidden { 
            display:none !important; 
        }
    </style>
</head>
<body class="shows-app">
    <div class="container">
        <div class="app-navigation">
            <button onclick="navigateTo('index')">Home</button>
            <button onclick="navigateTo('shows')">Show Tracker</button>
            <button onclick="navigateTo('manga')">Reading Tracker</button>
        </div>
        
        <header>
            <h1>Watch List</h1>
        </header>

        <button id='btnD' onclick="ShowAdd()">Add Show</button>
        <button id='btnU' class='btnAdd' onclick="HideAdd()">Cancel</button>

        <div class="add-show-form" id='addForm'>
            <h2>Add New Show</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" required placeholder="Show title">
                </div>
                
                <div class="form-group">
                    <label for="type">Type</label>
                    <select id="type" required>
                        <option value="k_drama">K-Drama</option>
                        <option value="anime">Anime</option>
                        <option value="american_series">Series</option>
                        <option value="movie">Movie</option>
                    </select>
                </div>
                
                <button class="add-btn" id="add-show-btn">Add Show</button>
            </div>

            <div class="clipboard-instructions">
                <p>Copy an image to clipboard or select an image file</p>
            </div>
            
            <div class="file-upload-container">
                <label class="file-input-label">
                    Select Image File
                    <input type="file" id="fileInput" accept="image/*" style="display: none">
                </label>
                <span id="fileName"></span>
            </div>
            <div id="fileError"></div>

            <div id="status-message" class="status-message hidden"></div>
        </div>

        <br>
        <br>

        <div class="sort-controls">
            <div class="filter-buttons">
                <a href="?app=shows&filter=all&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All Shows</a>
                <a href="?app=shows&filter=watched&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" class="filter-btn <?php echo $filter === 'watched' ? 'active' : ''; ?>">Watched</a>
                <a href="?app=shows&filter=ongoing&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" class="filter-btn <?php echo $filter === 'ongoing' ? 'active' : ''; ?>">Ongoing</a>
                <a href="?app=shows&filter=unwatched&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" class="filter-btn <?php echo $filter === 'unwatched' ? 'active' : ''; ?>">Unwatched</a>
            </div>
            
            <div class="type-buttons">
                <a href="?app=shows&filter=<?php echo $filter; ?>&type=all&sort=<?php echo $sort; ?>" 
                   class="type-btn <?php echo $type_filter === 'all' ? 'active' : ''; ?>">All Types</a>
                <a href="?app=shows&filter=<?php echo $filter; ?>&type=k_drama&sort=<?php echo $sort; ?>" 
                   class="type-btn k_drama <?php echo $type_filter === 'k_drama' ? 'active' : ''; ?>">K-Drama</a>
                <a href="?app=shows&filter=<?php echo $filter; ?>&type=anime&sort=<?php echo $sort; ?>" 
                   class="type-btn anime <?php echo $type_filter === 'anime' ? 'active' : ''; ?>">Anime</a>
                <a href="?app=shows&filter=<?php echo $filter; ?>&type=american_series&sort=<?php echo $sort; ?>" 
                   class="type-btn american_series <?php echo $type_filter === 'american_series' ? 'active' : ''; ?>">Series</a>
                <a href="?app=shows&filter=<?php echo $filter; ?>&type=movie&sort=<?php echo $sort; ?>" 
                   class="type-btn movie <?php echo $type_filter === 'movie' ? 'active' : ''; ?>">Movie</a>
            </div>
            
            <div class="combined-controls">
                <div class="sort-options">
                    <label for="sort">Sort by:</label>
                    <select id="sort" class="sort-dropdown" onchange="updateSort(this.value)">
                        <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Recently Added</option>
                        <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title (A-Z)</option>
                        <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title (Z-A)</option>
                        <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Status</option>
                    </select>
                </div>

                <div class="search-box">
                    <input type="text" id="search-input" class="search-input" placeholder="Search by title...">
                    <span class="search-icon">🔍</span>
                </div>
            </div>
        </div>

        <h2>Your Shows</h2>
        <div class="shows-grid" id="shows-container">
            <?php if (empty($shows)): ?>
                <div class="no-shows">No shows found matching your filter.</div>
            <?php else: ?>
                <?php foreach ($shows as $show): ?>
                    <div class="show-card" data-id="<?php echo $show['id']; ?>" data-title="<?php echo htmlspecialchars(strtolower($show['title'])); ?>">
                        <img src="<?php echo $show['image_path']; ?>" alt="<?php echo htmlspecialchars($show['title']); ?>" class="show-image" onclick="openShowModal(<?php echo $show['id']; ?>, '<?php echo htmlspecialchars($show['title']); ?>', '<?php echo $show['image_path']; ?>', '<?php echo htmlspecialchars($show['synopsis'] ?? ''); ?>')">
                        <div class="show-info">
                            <h3 class="show-title" onclick="openShowModal(<?php echo $show['id']; ?>, '<?php echo htmlspecialchars($show['title']); ?>', '<?php echo $show['image_path']; ?>', '<?php echo htmlspecialchars($show['synopsis'] ?? ''); ?>')"><?php echo htmlspecialchars($show['title']); ?></h3>
                            <div class="show-meta">
                                <span class="show-type <?php echo $show['type']; ?>">
                                    <?php 
                                    $typeNames = [
                                        'k_drama' => 'K-Drama',
                                        'anime' => 'Anime',
                                        'american_series' => 'Series',
                                        'movie' => 'Movie'
                                    ];
                                    echo $typeNames[$show['type']]; 
                                    ?>
                                </span>
                            </div>
                            <div class="show-synopsis hidden"><?php echo htmlspecialchars($show['synopsis'] ?? ''); ?></div>
                            <div class="show-actions">
                                <button class="status-btn <?php echo $show['status']; ?>">
                                    <?php echo ucfirst($show['status']); ?>
                                </button>
                                <button class="delete-btn">Delete</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <button class="back-to-top" id="backToTop">↑</button>

    <!-- Show View Modal -->
    <div class="show-modal-backdrop" id="showModalBackdrop" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="show-modal" role="document">
            <div class="left">
                <img id="modalShowImage" src="" alt="Poster">
            </div>
            <div class="right">
                <button class="close-btn" onclick="closeShowModal()">×</button>
                <h3 id="modalShowTitle">Show Title</h3>
                
                <div class="synopsis-container">
                    <h4>Synopsis</h4>
                    <div class="synopsis-text" id="modalShowSynopsisText"></div>
                    <button class="edit-btn" onclick="toggleEditSynopsis()">Edit Synopsis</button>
                    
                    <div class="edit-synopsis" id="editSynopsisContainer">
                        <textarea id="modalShowSynopsisEdit" placeholder="Enter synopsis..."></textarea>
                        <div class="edit-actions">
                            <button onclick="saveSynopsis()">Save</button>
                            <button onclick="toggleEditSynopsis()">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('btnU').style.display='none';
        document.getElementById('addForm').style.display='none';
        
        let selectedFile = null;
        let currentShowId = null;

        function ShowAdd() {
            document.getElementById('btnD').style.display='none';
            document.getElementById('btnU').style.display='block';
            document.getElementById('addForm').style.display='block';
            document.getElementById('title').focus();
        }
        
        function HideAdd() {
            document.getElementById('btnU').style.display='none';
            document.getElementById('btnD').style.display='block';
            document.getElementById('addForm').style.display='none';
        }

        function navigateTo(app) {
            window.location.href = app + '.php';
        }
        
        // Setup file input for Show Tracker
        document.getElementById('fileInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file type
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    document.getElementById('fileError').textContent = 'Invalid file type. Please select an image file.';
                    document.getElementById('fileError').style.display = 'block';
                    selectedFile = null;
                    document.getElementById('fileName').textContent = '';
                    return;
                }
                
                // Validate file size (max 5MB)
                if (file.size > 10 * 1024 * 1024) {
                    document.getElementById('fileError').textContent = 'File too large (max 10MB)';
                    document.getElementById('fileError').style.display = 'block';
                    selectedFile = null;
                    document.getElementById('fileName').textContent = '';
                    return;
                }
                
                selectedFile = file;
                document.getElementById('fileName').textContent = file.name;
                document.getElementById('fileError').style.display = 'none';
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            const addShowBtn = document.getElementById('add-show-btn');
            const showsContainer = document.getElementById('shows-container');
            const statusMessage = document.getElementById('status-message');
            const searchInput = document.getElementById('search-input');
            const backToTopBtn = document.getElementById('backToTop');

            // Back to top functionality
            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    backToTopBtn.classList.add('show');
                } else {
                    backToTopBtn.classList.remove('show');
                }
            });
            
            backToTopBtn.addEventListener('click', function() {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            // Search functionality
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.trim().toLowerCase();
                const showCards = document.querySelectorAll('.show-card');
                let visibleCount = 0;
                
                showCards.forEach(card => {
                    const title = card.dataset.title;
                    if (searchTerm === '' || title.includes(searchTerm)) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Handle no results message
                const noShowsElement = document.querySelector('.no-shows');
                if (visibleCount === 0) {
                    // Create message if it doesn't exist
                    if (!noShowsElement) {
                        const noShowsDiv = document.createElement('div');
                        noShowsDiv.className = 'no-shows';
                        noShowsDiv.textContent = 'No shows found matching your search.';
                        showsContainer.appendChild(noShowsDiv);
                    } else {
                        noShowsElement.textContent = 'No shows found matching your search.';
                    }
                } else {
                    // Remove message if it exists
                    if (noShowsElement) {
                        noShowsElement.remove();
                    }
                }
            });

            addShowBtn.addEventListener('click', async function() {
                const title = document.getElementById('title').value.trim();
                const type = document.getElementById('type').value;
                
                if (!title) {
                    showStatus('Please enter a title', 'error');
                    return;
                }

                let imageBlob = null;
                
                // First try clipboard
                try {
                    const clipboardItems = await navigator.clipboard.read();
                    for (const item of clipboardItems) {
                        for (const type of item.types) {
                            if (type.startsWith('image/')) {
                                imageBlob = await item.getType(type);
                                break;
                            }
                        }
                        if (imageBlob) break;
                    }
                } catch (error) {
                    console.log('Clipboard API error:', error);
                }
                
                // If no clipboard image, try selected file
                if (!imageBlob && selectedFile) {
                    imageBlob = selectedFile;
                }
                
                // If still no image, check for fallback paste
                if (!imageBlob && window.lastPastedImage) {
                    imageBlob = window.lastPastedImage;
                }
                
                // If no image found anywhere
                if (!imageBlob) {
                    showStatus('No image available. Please copy an image to clipboard or select a file.', 'error');
                    return;
                }

                try {
                    const reader = new FileReader();
                    reader.onload = function() {
                        const base64data = reader.result;
                        fetch('shows.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                'add_show': '1',
                                'title': title,
                                'type': type,
                                'image_data': base64data
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showStatus(data.message, 'success');
                                setTimeout(() => location.reload(), 1000);
                            } else {
                                showStatus(data.message, 'error');
                            }
                        })
                        .catch(error => {
                            showStatus('Error: ' + error.message, 'error');
                        });
                    };
                    
                    if (imageBlob instanceof File) {
                        reader.readAsDataURL(imageBlob);
                    } else {
                        // Convert Blob to File-like object
                        const file = new File([imageBlob], 'clipboard-image.png', { type: imageBlob.type });
                        reader.readAsDataURL(file);
                    }
                } catch (error) {
                    console.error('Error processing image:', error);
                    showStatus('Error processing image: ' + error.message, 'error');
                }
            });

            showsContainer.addEventListener('click', function(e) {
                const statusBtn = e.target.closest('.status-btn');
                const deleteBtn = e.target.closest('.delete-btn');

                if (statusBtn) {
                    const card = statusBtn.closest('.show-card');
                    const showId = card.dataset.id;
                    const currentStatus = statusBtn.classList.contains('watched') ? 'watched' : 
                                        statusBtn.classList.contains('ongoing') ? 'ongoing' : 'unwatched';
                    
                    // Determine next status in the cycle
                    let newStatus;
                    if (currentStatus === 'unwatched') {
                        newStatus = 'ongoing';
                    } else if (currentStatus === 'ongoing') {
                        newStatus = 'watched';
                    } else {
                        newStatus = 'unwatched';
                    }

                    fetch('shows.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            'update_status': '1',
                            'id': showId,
                            'status': newStatus
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update button appearance
                            statusBtn.className = 'status-btn ' + newStatus;
                            statusBtn.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                        }
                    });
                }

                if (deleteBtn) {
                    if (!confirm('Are you sure you want to delete this show?')) return;
                    const card = deleteBtn.closest('.show-card');
                    const showId = card.dataset.id;

                    fetch('shows.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            'delete_show': '1',
                            'id': showId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            card.remove();
                            if (document.querySelectorAll('.show-card').length === 0) {
                                showsContainer.innerHTML = '<div class="no-shows">No shows found matching your filter.</div>';
                            }
                        }
                    });
                }
            });

            function showStatus(message, type) {
                statusMessage.textContent = message;
                statusMessage.className = 'status-message ' + type;
                statusMessage.classList.remove('hidden');
                setTimeout(() => statusMessage.classList.add('hidden'), 5000);
            }
        });

        function updateSort(sortValue) {
            const url = new URL(window.location.href);
            url.searchParams.set('sort', sortValue);
            window.location.href = url.toString();
        }
        
        // Store pasted images for fallback
        window.lastPastedImage = null;
        document.addEventListener('paste', (event) => {
            const items = event.clipboardData.items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    window.lastPastedImage = items[i].getAsFile();
                    break;
                }
            }
        });

        // Show Modal Functions
        function openShowModal(id, title, image, synopsis) {
            currentShowId = id;
            document.getElementById('modalShowTitle').textContent = title;
            document.getElementById('modalShowImage').src = image;
            document.getElementById('modalShowSynopsisText').textContent = synopsis || 'No synopsis available.';
            document.getElementById('modalShowSynopsisEdit').value = synopsis || '';
            
            document.body.classList.add('modal-open');
            const bd = document.getElementById('showModalBackdrop');
            bd.style.display = 'flex'; 
            bd.setAttribute('aria-hidden','false');
            
            // Hide edit mode by default
            document.getElementById('editSynopsisContainer').style.display = 'none';
        }

        function closeShowModal() {
            document.body.classList.remove('modal-open');
            const bd = document.getElementById('showModalBackdrop');
            bd.style.display = 'none'; 
            bd.setAttribute('aria-hidden','true');
        }

        function toggleEditSynopsis() {
            const editContainer = document.getElementById('editSynopsisContainer');
            const isEditing = editContainer.style.display === 'block';
            
            if (isEditing) {
                editContainer.style.display = 'none';
            } else {
                editContainer.style.display = 'block';
                document.getElementById('modalShowSynopsisEdit').focus();
            }
        }

        function saveSynopsis() {
            if (!currentShowId) return;
            
            const synopsis = document.getElementById('modalShowSynopsisEdit').value;
            const formData = new FormData();
            formData.append('update_synopsis', '1');
            formData.append('id', currentShowId);
            formData.append('synopsis', synopsis);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the synopsis text
                    document.getElementById('modalShowSynopsisText').textContent = synopsis || 'No synopsis available.';
                    
                    // Update hidden synopsis in card
                    const card = document.querySelector('.show-card[data-id="' + currentShowId + '"]');
                    if (card) {
                        const s = card.querySelector('.show-synopsis');
                        if (s) s.textContent = synopsis;
                    }
                    
                    // Hide edit mode
                    document.getElementById('editSynopsisContainer').style.display = 'none';
                    
                    alert('Synopsis saved successfully!');
                } else {
                    alert('Error: ' + (data.message || 'Failed to save synopsis'));
                }
            })
            .catch(error => {
                alert('Request failed: ' + error.message);
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>