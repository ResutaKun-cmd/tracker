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
        'database' => "tracker"
    ]
];

// Determine which app to show
$app = 'manga';

// Handle Image Uploads
if (isset($_GET['action']) && $_GET['action'] === 'image_upload') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'manga';
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

// MANGA/MANHWA TRACKER SYSTEM
$conn = new mysqli(
    $db_config['manga']['servername'],
    $db_config['manga']['username'],
    $db_config['manga']['password'],
    $db_config['manga']['database']
);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS manga (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    type ENUM('manga', 'manhwa', 'manhua') NOT NULL,
    status ENUM('reading', 'completed', 'on_hold', 'dropped', 'plan_to_read') NOT NULL,
    current_chapter INT DEFAULT 0,
    total_chapters INT DEFAULT NULL,
    image_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_manga'])) {
        $title = $conn->real_escape_string($_POST['title']);
        $type = $conn->real_escape_string($_POST['type']);
        $status = $conn->real_escape_string($_POST['status']);
        $current_chapter = intval($_POST['current_chapter']);
        $total_chapters = !empty($_POST['total_chapters']) ? intval($_POST['total_chapters']) : NULL;

        if (isset($_POST['image_data'])) {
            $imageData = $_POST['image_data'];
            $imageData = str_replace('data:image/png;base64,', '', $imageData);
            $imageData = str_replace(' ', '+', $imageData);
            $imageBinary = base64_decode($imageData);

            $uploadsDir = 'uploads/manga/';
            if (!file_exists($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }

            $filename = uniqid() . '.png';
            $filepath = $uploadsDir . $filename;

            if (file_put_contents($filepath, $imageBinary)) {
                $sql = "INSERT INTO manga (title, type, status, current_chapter, total_chapters, image_path) 
                        VALUES ('$title', '$type', '$status', $current_chapter, ";
                $sql .= $total_chapters ? "$total_chapters" : "NULL";
                $sql .= ", '$filepath')";
                
                if ($conn->query($sql)) {
                    echo json_encode(['success' => true, 'message' => 'Manga added successfully!']);
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
    } elseif (isset($_POST['update_manga'])) {
        $id = intval($_POST['id']);
        $status = $conn->real_escape_string($_POST['status']);
        $current_chapter = intval($_POST['current_chapter']);
        $total_chapters = !empty($_POST['total_chapters']) ? intval($_POST['total_chapters']) : NULL;

        $sql = "UPDATE manga SET 
                status = '$status', 
                current_chapter = $current_chapter, 
                total_chapters = " . ($total_chapters ? "$total_chapters" : "NULL") . "
                WHERE id = $id";

        if ($conn->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    } elseif (isset($_POST['delete_manga'])) {
        $id = intval($_POST['id']);
        $sql = "SELECT image_path FROM manga WHERE id = $id";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (file_exists($row['image_path'])) {
                unlink($row['image_path']);
            }
        }

        $sql = "DELETE FROM manga WHERE id = $id";
        if ($conn->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    }
}

// Get filter parameter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Get sort parameter
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recent';

// Get type filter
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Build SQL query based on filters
$sql = "SELECT * FROM manga";
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
    case 'progress':
        $sql .= " ORDER BY (current_chapter/total_chapters) DESC";
        break;
    case 'recent':
    default:
        $sql .= " ORDER BY updated_at DESC";
        break;
}

$result = $conn->query($sql);
$manga_list = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $manga_list[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reading Tracker</title>
    <style>
        :root {
            --dark-bg: #1a1a1a;
            --card-bg: #2d2d2d;
            --text-color: #e0e0e0;
            --accent-color: #e74c3c;
            --reading-color: #3498db;
            --completed-color: #2ecc71;
            --onhold-color: #f39c12;
            --dropped-color: #e74c3c;
            --plantoread-color: #9b59b6;
            --manga-color: #3498db;
            --manhwa-color: #e74c3c;
            --manhua-color: #f1c40f;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body.manga-app {
            background-color: var(--dark-bg);
            color: var(--text-color);
            padding: 20px;
            position: relative;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
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
        
        .add-manga-form {
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
            background-color: #c0392b;
        }
        
        .manga-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .manga-card {
            background-color: var(--card-bg);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            position: relative;
        }
        
        .manga-image {
            width: 100%;
            height: 550px;
            object-fit: cover;
        }
        
        .manga-info {
            padding: 15px;
        }
        
        .manga-title {
            font-size: 1.2rem;
            margin-bottom: 5px;
            color: var(--accent-color);
        }
        
        .manga-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
            color: #aaa;
        }
        
        .manga-type {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .manga-type.manga {
            background-color: var(--manga-color);
            color: white;
        }
        
        .manga-type.manhwa {
            background-color: var(--manhwa-color);
            color: white;
        }
        
        .manga-type.manhua {
            background-color: var(--manhua-color);
            color: black;
        }
        
        .progress-container {
            margin: 10px 0;
            background-color: #444;
            border-radius: 4px;
            height: 10px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background-color: var(--reading-color);
        }
        
        .progress-text {
            font-size: 0.8rem;
            text-align: center;
            margin-top: 5px;
        }
        
        .manga-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        
        .status-btn {
            flex-grow: 1;
            margin-right: 10px;
            color: white;
        }
        
        .reading {
            background-color: var(--reading-color);
        }
        
        .completed {
            background-color: var(--completed-color);
        }
        
        .on_hold {
            background-color: var(--onhold-color);
        }
        
        .dropped {
            background-color: var(--dropped-color);
        }
        
        .plan_to_read {
            background-color: var(--plantoread-color);
        }
        
        .delete-btn {
            background-color: #333;
            color: var(--text-color);
        }
        
        .delete-btn:hover {
            background-color: #444;
        }
        
        .no-manga {
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
            background-color: rgba(46, 204, 113, 0.2);
            color: var(--completed-color);
            display: none;
        }
        
        .error {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--dropped-color);
            display: none;
        }
        
        .clipboard-instructions {
            margin-top: 15px;
            padding: 10px;
            background-color: rgba(231, 76, 60, 0.1);
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
            background-color: #c0392b;
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
            margin-bottom: 15px;
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
            background-color: #c0392b;
        }
        
        #fileError {
            color: #f44336;
            margin-top: 5px;
            font-size: 0.9rem;
            display: none;
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
            
            .form-grid {
                grid-template-columns: 1fr;
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
        
        .type-btn.active.manga {
            background-color: var(--manga-color);
        }
        
        .type-btn.active.manhwa {
            background-color: var(--manhwa-color);
        }
        
        .type-btn.active.manhua {
            background-color: var(--manhua-color);
            color: black;
        }
        
        .chapter-inputs {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .chapter-inputs input {
            width: 60px;
            text-align: center;
        }
        
        .chapter-separator {
            color: #777;
        }
        
        .view-btn {
            padding: 0px; 
            background-color: transparent; 
            color: white; 
            text-decoration: underline;
            border: none;
            cursor: pointer;
        }
        
        .manga-synopsis {
            display: none;
        }
    </style>
</head>

<body class="manga-app">
    <div class="container">
        <div class="app-navigation">
            <button onclick="navigateTo('anime')">Anime Tracker</button>
            <button onclick="navigateTo('shows')">Show Tracker</button>
            <button onclick="navigateTo('manga')">Reading Tracker</button>
        </div>
        
        <header>
            <h1>Reading Tracker</h1>
        </header>

        <button id='btnD' onclick="ShowAdd()">Add Reading Material</button>
        <button id='btnU' class='btnAdd' onclick="HideAdd()">Cancel</button>

        <div class="add-manga-form" id='addForm'>
            <h2>Add New Manga/Manhwa</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" required placeholder="Title">
                </div>
                
                <div class="form-group">
                    <label for="type">Type</label>
                    <select id="type" required>
                        <option value="manga">Manga</option>
                        <option value="manhwa">Manhwa</option>
                        <option value="manhua">Manhua</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" required>
                        <option value="reading">Reading</option>
                        <option value="completed">Completed</option>
                        <option value="on_hold">On Hold</option>
                        <option value="dropped">Dropped</option>
                        <option value="plan_to_read">Plan to Read</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Progress</label>
                    <div class="chapter-inputs">
                        <input type="number" id="current_chapter" min="0" value="0" required>
                        <span class="chapter-separator">/</span>
                        <input type="number" id="total_chapters" min="0" placeholder="?">
                    </div>
                </div>
                
                <button class="add-btn" id="add-manga-btn">Add Manga</button>
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
                <a href="?app=manga&filter=all&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" 
                   class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
                <a href="?app=manga&filter=reading&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" 
                   class="filter-btn <?php echo $filter === 'reading' ? 'active' : ''; ?>">Reading</a>
                <a href="?app=manga&filter=completed&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" 
                   class="filter-btn <?php echo $filter === 'completed' ? 'active' : ''; ?>">Completed</a>
                <a href="?app=manga&filter=on_hold&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" 
                   class="filter-btn <?php echo $filter === 'on_hold' ? 'active' : ''; ?>">On Hold</a>
                <a href="?app=manga&filter=dropped&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" 
                   class="filter-btn <?php echo $filter === 'dropped' ? 'active' : ''; ?>">Dropped</a>
                <a href="?app=manga&filter=plan_to_read&type=<?php echo $type_filter; ?>&sort=<?php echo $sort; ?>" 
                   class="filter-btn <?php echo $filter === 'plan_to_read' ? 'active' : ''; ?>">Plan to Read</a>
            </div>
            
            <div class="type-buttons">
                <a href="?app=manga&filter=<?php echo $filter; ?>&type=all&sort=<?php echo $sort; ?>" 
                   class="type-btn <?php echo $type_filter === 'all' ? 'active' : ''; ?>">All Types</a>
                <a href="?app=manga&filter=<?php echo $filter; ?>&type=manga&sort=<?php echo $sort; ?>" 
                   class="type-btn manga <?php echo $type_filter === 'manga' ? 'active' : ''; ?>">Manga</a>
                <a href="?app=manga&filter=<?php echo $filter; ?>&type=manhwa&sort=<?php echo $sort; ?>" 
                   class="type-btn manhwa <?php echo $type_filter === 'manhwa' ? 'active' : ''; ?>">Manhwa</a>
                <a href="?app=manga&filter=<?php echo $filter; ?>&type=manhua&sort=<?php echo $sort; ?>" 
                   class="type-btn manhua <?php echo $type_filter === 'manhua' ? 'active' : ''; ?>">Manhua</a>
            </div>
            
            <div class="combined-controls">
                <div class="sort-options">
                    <label for="sort">Sort by:</label>
                    <select id="sort" class="sort-dropdown" onchange="updateSort(this.value)">
                        <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Recently Updated</option>
                        <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title (A-Z)</option>
                        <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title (Z-A)</option>
                        <option value="progress" <?php echo $sort === 'progress' ? 'selected' : ''; ?>>Progress</option>
                    </select>
                </div>

                <div class="search-box">
                    <input type="text" id="search-input" class="search-input" placeholder="Search by title...">
                    <span class="search-icon">🔍</span>
                </div>
            </div>
        </div>

        <h2>Your Collection</h2>
        <div class="manga-grid" id="manga-container">
            <?php if (empty($manga_list)): ?>
                <div class="no-manga">No manga found matching your filter.</div>
            <?php else: ?>
                <?php foreach ($manga_list as $manga): ?>
                    <div class="manga-card" data-id="<?php echo $manga['id']; ?>" data-title="<?php echo htmlspecialchars(strtolower($manga['title'])); ?>">
                        <img src="<?php echo $manga['image_path']; ?>" alt="<?php echo htmlspecialchars($manga['title']); ?>" class="manga-image">
                        <div class="manga-info">
                            <h3 class="manga-title"><?php echo htmlspecialchars($manga['title']); ?></h3>
                            <div class="manga-meta">
                                <span class="manga-type <?php echo $manga['type']; ?>">
                                    <?php echo ucfirst($manga['type']); ?>
                                </span>
                                <span><?php echo ucfirst(str_replace('_', ' ', $manga['status'])); ?></span>
                            </div>
                            
                            <?php if ($manga['total_chapters'] && $manga['status'] !== 'plan_to_read'): ?>
                                <div class="progress-container">
                                    <?php 
                                        $progress = ($manga['current_chapter'] / $manga['total_chapters']) * 100;
                                        $progress = min(100, max(0, $progress)); // Ensure between 0-100
                                    ?>
                                    <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                                <div class="progress-text">
                                    <?php echo $manga['current_chapter']; ?> / <?php echo $manga['total_chapters'] ?: '?'; ?> chapters
                                </div>
                            <?php elseif ($manga['status'] === 'plan_to_read'): ?>
                                <div class="progress-text">Plan to Read</div>
                            <?php else: ?>
                                <div class="progress-text">Chapter <?php echo $manga['current_chapter']; ?></div>
                            <?php endif; ?>
                            
                            <div class="manga-actions">
                                <button class="status-btn <?php echo $manga['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $manga['status'])); ?>
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

    <!-- Edit Modal -->
    <div id="editModal" class="hidden" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); z-index: 1000; display: flex; justify-content: center; align-items: center;">
        <div style="background-color: var(--card-bg); padding: 20px; border-radius: 8px; width: 90%; max-width: 500px;">
            <h2 style="margin-bottom: 20px;">Edit Manga</h2>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="edit-status">Status</label>
                    <select id="edit-status" class="edit-input">
                        <option value="reading">Reading</option>
                        <option value="completed">Completed</option>
                        <option value="on_hold">On Hold</option>
                        <option value="dropped">Dropped</option>
                        <option value="plan_to_read">Plan to Read</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Progress</label>
                    <div class="chapter-inputs">
                        <input type="number" id="edit-current" min="0" value="0" class="edit-input">
                        <span class="chapter-separator">/</span>
                        <input type="number" id="edit-total" min="0" placeholder="?" class="edit-input">
                    </div>
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button id="cancelEdit" style="background-color: #444;">Cancel</button>
                <button id="saveEdit" style="background-color: var(--accent-color);">Save Changes</button>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('btnU').style.display='none';
        document.getElementById('addForm').style.display='none';
        document.getElementById('editModal').style.visibility = "hidden";
        
        let selectedFile = null;
        let currentEditId = null;

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
        
        // Setup file input for Manga Tracker
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
            const addMangaBtn = document.getElementById('add-manga-btn');
            const mangaContainer = document.getElementById('manga-container');
            const statusMessage = document.getElementById('status-message');
            const searchInput = document.getElementById('search-input');
            const backToTopBtn = document.getElementById('backToTop');
            const editModal = document.getElementById('editModal');
            const cancelEditBtn = document.getElementById('cancelEdit');
            const saveEditBtn = document.getElementById('saveEdit');

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
                const mangaCards = document.querySelectorAll('.manga-card');
                let visibleCount = 0;
                
                mangaCards.forEach(card => {
                    const title = card.dataset.title;
                    if (searchTerm === '' || title.includes(searchTerm)) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Handle no results message
                const noMangaElement = document.querySelector('.no-manga');
                if (visibleCount === 0) {
                    // Create message if it doesn't exist
                    if (!noMangaElement) {
                        const noMangaDiv = document.createElement('div');
                        noMangaDiv.className = 'no-manga';
                        noMangaDiv.textContent = 'No manga found matching your search.';
                        mangaContainer.appendChild(noMangaDiv);
                    } else {
                        noMangaElement.textContent = 'No manga found matching your search.';
                    }
                } else {
                    // Remove message if it exists
                    if (noMangaElement) {
                        noMangaElement.remove();
                    }
                }
            });

            addMangaBtn.addEventListener('click', async function() {
                const title = document.getElementById('title').value.trim();
                const type = document.getElementById('type').value;
                const status = document.getElementById('status').value;
                const currentChapter = parseInt(document.getElementById('current_chapter').value);
                const totalChapters = document.getElementById('total_chapters').value ? parseInt(document.getElementById('total_chapters').value) : null;

                if (!title) {
                    showStatus('Please enter a title', 'error');
                    return;
                }

                if (currentChapter < 0) {
                    showStatus('Current chapter cannot be negative', 'error');
                    return;
                }

                if (totalChapters !== null && totalChapters < 0) {
                    showStatus('Total chapters cannot be negative', 'error');
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
                        fetch('manga.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                'add_manga': '1',
                                'title': title,
                                'type': type,
                                'status': status,
                                'current_chapter': currentChapter,
                                'total_chapters': totalChapters || '',
                                'image_data': base64data
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showStatus(data.message, 'success');
                                setTimeout(() => location.reload(), 1000);
                                document.getElementById('addForm').reset();
                                document.getElementById('fileName').textContent = '';
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

            // Handle status button clicks to show edit modal
            mangaContainer.addEventListener('click', function(e) {
                const statusBtn = e.target.closest('.status-btn');
                const deleteBtn = e.target.closest('.delete-btn');

                if (statusBtn) {
                    const card = statusBtn.closest('.manga-card');
                    currentEditId = card.dataset.id;
                    
                    // Get current values
                    const currentStatus = statusBtn.className.split(' ')[1];
                    const progressText = card.querySelector('.progress-text').textContent;
                    
                    // Parse chapter info
                    let currentChapter = 0;
                    let totalChapters = null;
                    
                    if (progressText.includes('/')) {
                        const parts = progressText.split('/');
                        currentChapter = parseInt(parts[0].trim());
                        totalChapters = parts[1].includes('?') ? null : parseInt(parts[1].trim());
                    } else if (progressText.includes('Chapter')) {
                        currentChapter = parseInt(progressText.replace('Chapter', '').trim());
                    }
                    
                    // Set values in modal
                    document.getElementById('edit-status').value = currentStatus;
                    document.getElementById('edit-current').value = currentChapter;
                    document.getElementById('edit-total').value = totalChapters || '';
                    
                    // Show modal
                    document.getElementById('editModal').style.visibility = "visible";
                }

                if (deleteBtn) {
                    if (!confirm('Are you sure you want to delete this manga?')) return;
                    const card = deleteBtn.closest('.manga-card');
                    const mangaId = card.dataset.id;

                    fetch('manga.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            'delete_manga': '1',
                            'id': mangaId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            card.remove();
                            if (document.querySelectorAll('.manga-card').length === 0) {
                                mangaContainer.innerHTML = '<div class="no-manga">No manga found matching your filter.</div>';
                            }
                        }
                    });
                }
            });

            // Handle modal buttons
            cancelEditBtn.addEventListener('click', function() {
                document.getElementById('editModal').style.visibility = "hidden";
                currentEditId = null;
            });

            saveEditBtn.addEventListener('click', function() {
                const newStatus = document.getElementById('edit-status').value;
                const newCurrent = parseInt(document.getElementById('edit-current').value);
                const newTotal = document.getElementById('edit-total').value ? parseInt(document.getElementById('edit-total').value) : null;

                if (newCurrent < 0) {
                    alert('Current chapter cannot be negative');
                    return;
                }

                if (newTotal !== null && newTotal < 0) {
                    alert('Total chapters cannot be negative');
                    return;
                }

                fetch('manga.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        'update_manga': '1',
                        'id': currentEditId,
                        'status': newStatus,
                        'current_chapter': newCurrent,
                        'total_chapters': newTotal || ''
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error updating manga: ' + (data.message || 'Unknown error'));
                    }
                });
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
    </script>

<!-- Edit Modal for Show Tracker -->
<div id="editModal" class="edit-form hidden">
  <h3>Edit Show</h3>
  <input type="hidden" id="edit-id">
  <label>Title:</label>
  <input type="text" id="edit-title">
  <label>Type:</label>
  <select id="edit-type">
    <option value="k_drama">K-Drama</option>
    <option value="anime">Anime</option>
    <option value="american_series">Series</option>
    <option value="movie">Movie</option>
  </select>
  <label>Status:</label>
  <select id="edit-status">
    <option value="unwatched">Unwatched</option>
    <option value="ongoing">Ongoing</option>
    <option value="watched">Watched</option>
  </select>
  <div class="form-actions">
    <button onclick="submitEditShow()">Save</button>
    <button onclick="closeEditModal()">Cancel</button>
  </div>
</div>

<script>
function openEditModal(id, title, type, status) {
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-title').value = title;
  document.getElementById('edit-type').value = type;
  document.getElementById('edit-status').value = status;
  document.getElementById('editModal').classList.remove('hidden');
}
function closeEditModal() {
  document.getElementById('editModal').classList.add('hidden');
}
function submitEditShow() {
  const id = document.getElementById('edit-id').value;
  const title = document.getElementById('edit-title').value;
  const type = document.getElementById('edit-type').value;
  const status = document.getElementById('edit-status').value;

  const formData = new FormData();
  formData.append('update_show', '1');
  formData.append('id', id);
  formData.append('title', title);
  formData.append('type', type);
  formData.append('status', status);

  fetch(window.location.href, {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alert('Show updated successfully');
      location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  })
  .catch(err => alert('Request failed'));
}
</script>


<!-- Show View Modal -->
<div class="show-modal-backdrop" id="showModalBackdrop" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="show-modal" role="document">
    <div class="left">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h3 id="modalShowTitle">Show Title</h3>
        <button class="close-btn" onclick="closeShowModal()">×</button>
      </div>
      <label>Synopsis</label>
      <textarea id="modalShowSynopsis"></textarea>
      <div class="actions">
        <button class="btn" id="modalSaveSynopsis">Save</button>
        <span id="modalSaveStatus" class="hidden">Saving...</span>
      </div>
    </div>
    <div class="right">
      <img id="modalShowImage" src="" alt="Poster">
    </div>
  </div>
</div>

<style>
/* simple modal styles */
.show-modal-backdrop { position: fixed; inset:0; display:none; align-items:center; justify-content:center; background: rgba(0,0,0,0.6); z-index: 9999; }
.show-modal { width: min(900px, 96vw); background: #0f1520; border-radius:12px; display:grid; grid-template-columns: 1fr 300px; overflow:hidden; border:1px solid #243249; }
.show-modal .left { padding:16px; }
.show-modal .right { padding:16px; border-left:1px solid #1b2537; display:flex; align-items:center; justify-content:center; background:#081018; }
.show-modal .right img { width:100%; height:360px; object-fit:cover; border-radius:8px; }
.show-modal textarea { width:100%; min-height:220px; background:#07101b; color:#e6eefc; border-radius:8px; border:1px solid #1b2435; padding:10px; }
.show-modal .actions { margin-top:10px; display:flex; gap:8px; }
.show-modal .close-btn { background:transparent; border:none; color:#a9c5ee; font-size:22px; cursor:pointer; }
.hidden { display:none !important; }
</style>

<script>
// Attach click handlers to View buttons to open modal and blur background
document.addEventListener('DOMContentLoaded', function(){
    // manga: attach view button handlers to open modal using hidden synopsis
    document.querySelectorAll('.manga-card').forEach(function(card){
        const viewBtn = card.querySelector('.view-btn');
        if (!viewBtn) return;
        viewBtn.addEventListener('click', function(){
            const title = card.querySelector('.manga-title')?.textContent || '';
            const img = card.querySelector('.manga-image')?.getAttribute('src') || '';
            const synEl = card.querySelector('.manga-synopsis');
            const synopsis = synEl ? synEl.textContent : '';
            openShowModal(card.dataset.id, title, img, synopsis);
        });
    });
});

let currentShowId = null;
function openShowModal(id, title, img, synopsis) {
    currentShowId = id;
    document.getElementById('modalShowTitle').textContent = title;
    document.getElementById('modalShowImage').src = img;
    document.getElementById('modalShowSynopsis').value = synopsis || '';
    document.body.classList.add('modal-open');
    const bd = document.getElementById('showModalBackdrop');
    bd.style.display = 'flex'; bd.setAttribute('aria-hidden','false');
}
function closeShowModal(){
    document.body.classList.remove('modal-open');
    const bd = document.getElementById('showModalBackdrop');
    bd.style.display = 'none'; bd.setAttribute('aria-hidden','true');
}
document.getElementById('modalSaveSynopsis')?.addEventListener('click', function(){
    if (!currentShowId) return;
    const syn = document.getElementById('modalShowSynopsis').value;
    const fd = new FormData();
    fd.append('update_show', '1');
    fd.append('id', currentShowId);
    fd.append('synopsis', syn);
    document.getElementById('modalSaveStatus').classList.remove('hidden');
    fetch(window.location.href, { method: 'POST', body: fd }).then(r=>r.json()).then(data=>{
        document.getElementById('modalSaveStatus').classList.add('hidden');
        if (data.success) {
            alert('Synopsis saved');
            // update hidden synopsis in card
            const card = document.querySelector('.manga-card[data-id="'+currentShowId+'"]');
            if (card) {
                const s = card.querySelector('.manga-synopsis');
                if (s) s.textContent = syn;
            }
            closeShowModal();
        } else alert(data.message || 'Save failed');
    }).catch(e=>{ document.getElementById('modalSaveStatus').classList.add('hidden'); alert('Request failed'); });
});
</script>

</body>
</html>
<?php $conn->close(); ?>