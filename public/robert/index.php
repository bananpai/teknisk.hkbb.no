<?php
$config = json_decode(file_get_contents('config.json'), true);
$theme = $config['theme'] ?? [];
$colors = $theme['colors'] ?? [];
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Snake Spill - PHP Edition</title>
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --color-primary: <?php echo $colors['primary'] ?? '#4CAF50'; ?>;
            --color-secondary: <?php echo $colors['secondary'] ?? '#2196F3'; ?>;
            --color-background: <?php echo $colors['background'] ?? '#1a1a1a'; ?>;
            --color-surface: <?php echo $colors['surface'] ?? '#2d2d2d'; ?>;
            --color-text: <?php echo $colors['text'] ?? '#ffffff'; ?>;
            --color-text-secondary: <?php echo $colors['textSecondary'] ?? '#b0b0b0'; ?>;
            --color-snake: <?php echo $colors['snake'] ?? '#4CAF50'; ?>;
            --color-food: <?php echo $colors['food'] ?? '#FF5722'; ?>;
            --color-grid: <?php echo $colors['grid'] ?? '#333333'; ?>;
            --color-game-over: <?php echo $colors['gameOver'] ?? '#f44336'; ?>;
            --font-primary: <?php echo $theme['fonts']['primary'] ?? "'Segoe UI', sans-serif"; ?>;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🐍 Snake Spill</h1>
            <p class="subtitle">Bruk piltastene for å kontrollere slangen</p>
        </header>

        <div class="game-container">
            <div class="game-info">
                <div class="score-display">
                    <span class="label">Poeng:</span>
                    <span id="score" class="value">0</span>
                </div>
                <div class="high-score-display">
                    <span class="label">Rekord:</span>
                    <span id="highScore" class="value">0</span>
                </div>
            </div>

            <canvas id="gameCanvas" width="400" height="400"></canvas>

            <div class="game-controls">
                <button id="startBtn" class="btn btn-primary">Start Spill</button>
                <button id="pauseBtn" class="btn btn-secondary" disabled>Pause</button>
                <button id="resetBtn" class="btn btn-secondary">Tilbakestill</button>
            </div>

            <div id="gameOverModal" class="modal">
                <div class="modal-content">
                    <h2>Game Over!</h2>
                    <p class="final-score">Din poengsum: <span id="finalScore">0</span></p>
                    <input type="text" id="playerName" placeholder="Skriv inn navn" maxlength="20">
                    <button id="saveScoreBtn" class="btn btn-primary">Lagre Poengsum</button>
                    <button id="playAgainBtn" class="btn btn-secondary">Spill Igjen</button>
                </div>
            </div>
        </div>

        <div class="sidebar">
            <div class="panel">
                <h3>🏆 Toppliste</h3>
                <div id="highScoresList" class="high-scores-list">
                    <p class="loading">Laster...</p>
                </div>
            </div>

            <div class="panel">
                <h3>⚙️ Innstillinger</h3>
                <button id="themeBtn" class="btn btn-secondary">Endre Tema</button>
                <div id="themeEditor" class="theme-editor" style="display: none;">
                    <h4>Tilpass Farger</h4>
                    <div class="color-picker-group">
                        <label>Slange Farge:
                            <input type="color" id="snakeColor" value="<?php echo $colors['snake'] ?? '#4CAF50'; ?>">
                        </label>
                        <label>Mat Farge:
                            <input type="color" id="foodColor" value="<?php echo $colors['food'] ?? '#FF5722'; ?>">
                        </label>
                        <label>Bakgrunn:
                            <input type="color" id="backgroundColor" value="<?php echo $colors['background'] ?? '#1a1a1a'; ?>">
                        </label>
                        <label>Overflate:
                            <input type="color" id="surfaceColor" value="<?php echo $colors['surface'] ?? '#2d2d2d'; ?>">
                        </label>
                        <label>Primærfarge:
                            <input type="color" id="primaryColor" value="<?php echo $colors['primary'] ?? '#4CAF50'; ?>">
                        </label>
                    </div>
                    <button id="saveThemeBtn" class="btn btn-primary">Lagre Tema</button>
                    <button id="resetThemeBtn" class="btn btn-secondary">Tilbakestill</button>
                </div>
            </div>

            <div class="panel">
                <h3>📖 Kontroller</h3>
                <ul class="controls-list">
                    <li>⬆️ Pil opp - Gå opp</li>
                    <li>⬇️ Pil ned - Gå ned</li>
                    <li>⬅️ Pil venstre - Gå venstre</li>
                    <li>➡️ Pil høyre - Gå høyre</li>
                    <li>🎮 WASD - Alternative kontroller</li>
                    <li>␣ Mellomrom - Pause</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        const config = <?php echo json_encode($config); ?>;
    </script>
    <script src="game.js"></script>
</body>
</html>

