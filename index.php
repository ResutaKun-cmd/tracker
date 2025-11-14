<?php

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watch List</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a1a1a, #2d2d2d);
            color: white;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .container {
            text-align: center;
            padding: 40px;
        }
        
        h1 {
            font-size: 3rem;
            margin-bottom: 40px;
            color: #ff6b6b;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }
        
        .app-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .app-card {
            background: transparent; 
            position: relative; 
            border-radius: 15px;
            padding: 30px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.4); 
            overflow: hidden; 
        }
        
        .app-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.1); 
            z-index: 1;
            transition: background 0.3s ease;
        }

        .app-card > * {
            position: relative;
            z-index: 2; 
        }

        .app-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
        }

        .app-card:hover::before {
            background: rgba(0, 0, 0, 0.6); 
        }
        
        .app-card.anime {
            border-top: 5px solid #ff6b6b;
        }

        .app-card.anime:hover {
            background-image: url(<?php echo $themeBG; ?>);
            background-repeat: no-repeat;
            background-position: top center;
            background-size: cover;
        }

        .app-card.shows {
            border-top: 5px solid #4a8fe7;
        }

        .app-card.shows:hover {
            background-image: url("https://cdn.dribbble.com/userupload/25285395/file/original-176f400dc0f468a7f9e8e9afcfc4eda9.gif");
            background-repeat: no-repeat;
            background-size: cover;
            background-position: top center;

        }
        
        .app-card.manga {
            border-top: 5px solid #a6b830;
        }

        .app-card.manga:hover {
            background-image: url("https://giffiles.alphacoders.com/346/3465.gif");
            background-repeat: no-repeat;
            background-position: top center;
            background-size: cover;

        }
    
        .app-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
        
        .app-title {
            font-size: 1.8rem;
            margin-bottom: 15px;
        }
        
        .app-description {
            color: #ccc;
            line-height: 1.6;
        }
        
        @media (max-width: 768px) {
            .app-grid {
                grid-template-columns: 1fr;
            }
            
            h1 {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Watch List</h1>
        <div class="app-grid">
            <div class="app-card anime" onclick="navigateTo('anime')">
                <div class="app-icon">⛩️</div>
                <h2 class="app-title">Anime</h2>
                <p class="app-description">Track seasonal anime releases, manage your watchlist, and stay updated with new episodes.</p>
            </div>
            
            <div class="app-card shows" onclick="navigateTo('shows')">
                <div class="app-icon">📺</div>
                <h2 class="app-title">Tv Show & Movies</h2>
                <p class="app-description">Manage your TV shows, movies, and K-dramas with status tracking and progress monitoring.</p>
            </div>
            
            <div class="app-card manga" onclick="navigateTo('manga')">
                <div class="app-icon">📚</div>
                <h2 class="app-title">Books</h2>
                <p class="app-description">Track your manga, manhwa, and manhua reading progress with chapter management.</p>
            </div>
        </div>
    </div>

    <script>
        function navigateTo(app) {
            window.location.href = app + '.php';
        }
    </script>
</body>
</html>