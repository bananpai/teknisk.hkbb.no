// Snake Game Logic
class SnakeGame {
    constructor(canvas, config) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.config = config.game;
        this.colors = config.theme.colors;
        
        this.gridSize = this.config.gridSize;
        this.tileCount = canvas.width / this.gridSize;
        
        this.reset();
        this.loadHighScore();
    }
    
    reset() {
        this.snake = [{x: 10, y: 10}];
        this.velocityX = 0;
        this.velocityY = 0;
        this.foodX = 15;
        this.foodY = 15;
        this.score = 0;
        this.gameRunning = false;
        this.gamePaused = false;
        this.gameOver = false;
        this.speed = this.config.initialSpeed;
        
        this.updateScore();
        this.placeFood();
    }
    
    start() {
        if (this.gameRunning) return;
        
        this.reset();
        this.velocityX = 1;
        this.velocityY = 0;
        this.gameRunning = true;
        this.gamePaused = false;
        this.gameOver = false;
        
        this.hideModal();
        this.gameLoop();
    }
    
    pause() {
        if (!this.gameRunning || this.gameOver) return;
        this.gamePaused = !this.gamePaused;
        
        if (!this.gamePaused) {
            this.gameLoop();
        }
    }
    
    gameLoop() {
        if (!this.gameRunning || this.gamePaused || this.gameOver) return;
        
        setTimeout(() => {
            this.update();
            this.draw();
            this.gameLoop();
        }, this.speed);
    }
    
    update() {
        // Move snake
        const head = {
            x: this.snake[0].x + this.velocityX,
            y: this.snake[0].y + this.velocityY
        };
        
        // Check wall collision
        if (head.x < 0 || head.x >= this.tileCount || 
            head.y < 0 || head.y >= this.tileCount) {
            this.endGame();
            return;
        }
        
        // Check self collision
        for (let segment of this.snake) {
            if (head.x === segment.x && head.y === segment.y) {
                this.endGame();
                return;
            }
        }
        
        this.snake.unshift(head);
        
        // Check food collision
        if (head.x === this.foodX && head.y === this.foodY) {
            this.score++;
            this.updateScore();
            this.placeFood();
            
            // Increase speed slightly
            if (this.speed > 50) {
                this.speed -= this.config.speedIncrease;
            }
        } else {
            this.snake.pop();
        }
    }
    
    draw() {
        // Clear canvas
        this.ctx.fillStyle = this.colors.grid;
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        
        // Draw grid
        this.ctx.strokeStyle = this.colors.background;
        this.ctx.lineWidth = 1;
        for (let i = 0; i < this.tileCount; i++) {
            for (let j = 0; j < this.tileCount; j++) {
                this.ctx.strokeRect(
                    i * this.gridSize, 
                    j * this.gridSize, 
                    this.gridSize, 
                    this.gridSize
                );
            }
        }
        
        // Draw snake
        this.snake.forEach((segment, index) => {
            if (index === 0) {
                // Head - slightly brighter
                this.ctx.fillStyle = this.colors.snake;
                this.ctx.shadowBlur = 10;
                this.ctx.shadowColor = this.colors.snake;
            } else {
                this.ctx.fillStyle = this.colors.snake;
                this.ctx.globalAlpha = 0.8;
                this.ctx.shadowBlur = 0;
            }
            
            this.ctx.fillRect(
                segment.x * this.gridSize + 1,
                segment.y * this.gridSize + 1,
                this.gridSize - 2,
                this.gridSize - 2
            );
            
            this.ctx.globalAlpha = 1;
        });
        
        // Draw food
        this.ctx.fillStyle = this.colors.food;
        this.ctx.shadowBlur = 15;
        this.ctx.shadowColor = this.colors.food;
        this.ctx.beginPath();
        this.ctx.arc(
            this.foodX * this.gridSize + this.gridSize / 2,
            this.foodY * this.gridSize + this.gridSize / 2,
            this.gridSize / 2 - 2,
            0,
            Math.PI * 2
        );
        this.ctx.fill();
        this.ctx.shadowBlur = 0;
    }
    
    placeFood() {
        let validPosition = false;
        
        while (!validPosition) {
            this.foodX = Math.floor(Math.random() * this.tileCount);
            this.foodY = Math.floor(Math.random() * this.tileCount);
            
            validPosition = true;
            for (let segment of this.snake) {
                if (segment.x === this.foodX && segment.y === this.foodY) {
                    validPosition = false;
                    break;
                }
            }
        }
    }
    
    changeDirection(newVelocityX, newVelocityY) {
        // Prevent reversing
        if (this.velocityX === -newVelocityX && this.velocityY === -newVelocityY) {
            return;
        }
        
        this.velocityX = newVelocityX;
        this.velocityY = newVelocityY;
        
        // Start game on first move if not started
        if (!this.gameRunning && !this.gameOver) {
            this.start();
        }
    }
    
    updateScore() {
        document.getElementById('score').textContent = this.score;
    }
    
    async loadHighScore() {
        try {
            const response = await fetch('api.php?action=getScores');
            const data = await response.json();
            
            if (data.scores && data.scores.length > 0) {
                const highScore = data.scores[0].score;
                document.getElementById('highScore').textContent = highScore;
            }
        } catch (error) {
            console.error('Failed to load high score:', error);
        }
    }
    
    endGame() {
        this.gameRunning = false;
        this.gameOver = true;
        
        document.getElementById('finalScore').textContent = this.score;
        document.getElementById('gameOverModal').style.display = 'flex';
        
        this.loadHighScore();
    }
    
    hideModal() {
        document.getElementById('gameOverModal').style.display = 'none';
        document.getElementById('playerName').value = '';
    }
    
    updateColors(colors) {
        this.colors = colors;
        if (!this.gameRunning) {
            this.draw();
        }
    }
}

// Initialize game
let game;

document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('gameCanvas');
    game = new SnakeGame(canvas, config);
    
    // Draw initial state
    game.draw();
    
    // Button controls
    document.getElementById('startBtn').addEventListener('click', () => {
        game.start();
        document.getElementById('startBtn').disabled = true;
        document.getElementById('pauseBtn').disabled = false;
    });
    
    document.getElementById('pauseBtn').addEventListener('click', () => {
        game.pause();
        document.getElementById('pauseBtn').textContent = 
            game.gamePaused ? 'Fortsett' : 'Pause';
    });
    
    document.getElementById('resetBtn').addEventListener('click', () => {
        game.reset();
        game.draw();
        document.getElementById('startBtn').disabled = false;
        document.getElementById('pauseBtn').disabled = true;
        document.getElementById('pauseBtn').textContent = 'Pause';
    });
    
    // Keyboard controls
    document.addEventListener('keydown', (e) => {
        switch(e.key) {
            case 'ArrowUp':
            case 'w':
            case 'W':
                e.preventDefault();
                game.changeDirection(0, -1);
                break;
            case 'ArrowDown':
            case 's':
            case 'S':
                e.preventDefault();
                game.changeDirection(0, 1);
                break;
            case 'ArrowLeft':
            case 'a':
            case 'A':
                e.preventDefault();
                game.changeDirection(-1, 0);
                break;
            case 'ArrowRight':
            case 'd':
            case 'D':
                e.preventDefault();
                game.changeDirection(1, 0);
                break;
            case ' ':
                e.preventDefault();
                game.pause();
                document.getElementById('pauseBtn').textContent = 
                    game.gamePaused ? 'Fortsett' : 'Pause';
                break;
        }
    });
    
    // Game over modal
    document.getElementById('saveScoreBtn').addEventListener('click', async () => {
        const name = document.getElementById('playerName').value.trim() || 'Anonymous';
        
        try {
            const response = await fetch('api.php?action=saveScore', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name: name,
                    score: game.score
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('Poengsum lagret!');
                loadHighScores();
                game.loadHighScore();
            }
        } catch (error) {
            console.error('Failed to save score:', error);
            alert('Kunne ikke lagre poengsum');
        }
    });
    
    document.getElementById('playAgainBtn').addEventListener('click', () => {
        game.start();
        document.getElementById('startBtn').disabled = true;
        document.getElementById('pauseBtn').disabled = false;
    });
    
    // Load high scores
    loadHighScores();
    
    // Theme controls
    document.getElementById('themeBtn').addEventListener('click', () => {
        const editor = document.getElementById('themeEditor');
        editor.style.display = editor.style.display === 'none' ? 'block' : 'none';
    });
    
    document.getElementById('saveThemeBtn').addEventListener('click', async () => {
        const newConfig = {
            ...config,
            theme: {
                ...config.theme,
                colors: {
                    ...config.theme.colors,
                    snake: document.getElementById('snakeColor').value,
                    food: document.getElementById('foodColor').value,
                    background: document.getElementById('backgroundColor').value,
                    surface: document.getElementById('surfaceColor').value,
                    primary: document.getElementById('primaryColor').value
                }
            }
        };
        
        try {
            const response = await fetch('api.php?action=updateConfig', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(newConfig)
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('Tema lagret! Last inn siden på nytt for å se endringene.');
                location.reload();
            }
        } catch (error) {
            console.error('Failed to save theme:', error);
            alert('Kunne ikke lagre tema');
        }
    });
    
    document.getElementById('resetThemeBtn').addEventListener('click', async () => {
        if (!confirm('Tilbakestille til standard tema?')) return;
        
        const defaultConfig = {
            theme: {
                name: "default",
                colors: {
                    primary: "#4CAF50",
                    secondary: "#2196F3",
                    background: "#1a1a1a",
                    surface: "#2d2d2d",
                    text: "#ffffff",
                    textSecondary: "#b0b0b0",
                    snake: "#4CAF50",
                    food: "#FF5722",
                    grid: "#333333",
                    gameOver: "#f44336"
                },
                fonts: {
                    primary: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                }
            },
            game: config.game
        };
        
        try {
            const response = await fetch('api.php?action=updateConfig', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(defaultConfig)
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('Tema tilbakestilt!');
                location.reload();
            }
        } catch (error) {
            console.error('Failed to reset theme:', error);
        }
    });
});

async function loadHighScores() {
    try {
        const response = await fetch('api.php?action=getScores');
        const data = await response.json();
        
        const listElement = document.getElementById('highScoresList');
        
        if (data.scores && data.scores.length > 0) {
            listElement.innerHTML = data.scores.map((score, index) => `
                <div class="score-item">
                    <span class="rank">${index + 1}.</span>
                    <span class="name">${score.name}</span>
                    <span class="score">${score.score}</span>
                </div>
            `).join('');
        } else {
            listElement.innerHTML = '<p class="no-scores">Ingen poengsummer ennå</p>';
        }
    } catch (error) {
        console.error('Failed to load high scores:', error);
        document.getElementById('highScoresList').innerHTML = 
            '<p class="error">Kunne ikke laste poengsummer</p>';
    }
}

