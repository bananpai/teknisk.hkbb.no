# 🐍 Snake Spill - PHP Web App

Et moderne Snake-spill bygget med PHP, JavaScript og flat file JSON database. Spillet har et fullstendig tilpassbart tema-system og high score-funksjonalitet.

## 📋 Funksjoner

- **Klassisk Snake-spill** med moderne grafikk
- **High Score System** - Lagrer topp 10 resultater
- **Flat File Database** - Bruker JSON for datalagring
- **Tilpassbart Design** - Endre farger og tema i sanntid
- **Responsivt Design** - Fungerer på desktop, tablet og mobil
- **Multiple kontroller** - Piltaster, WASD og mellomrom for pause
- **Norsk grensesnitt**

## 🚀 Installasjon

### Forutsetninger

- PHP 7.4 eller nyere
- En webserver (Apache, Nginx, eller PHP's innebygde server)
- Moderne nettleser med JavaScript aktivert

### Oppsett

1. **Last ned prosjektet** til din webserver-mappe:
```bash
cd /din/webserver/mappe
```

2. **Sørg for at PHP har skrive-tilgang** til følgende filer:
   - `config.json`
   - `scores.json`

3. **Start webserveren**:

#### Alternativ 1: PHP's innebygde server (utviklingserveren)
```bash
php -S localhost:8000
```

#### Alternativ 2: Apache/Nginx
Plasser filene i din `htdocs` eller `www` mappe.

4. **Åpne nettleseren** og gå til:
   - Med PHP-server: `http://localhost:8000`
   - Med Apache/Nginx: `http://localhost/snake` (eller din konfigurerte path)

## 📁 Filstruktur

```
snake-game/
│
├── index.php           # Hovedside (HTML + PHP)
├── game.js            # Snake spill logikk
├── style.css          # Styling og tema
├── api.php            # Backend API for scores og config
├── config.json        # Konfigurasjonsfil (tema, farger)
├── scores.json        # High scores database
└── README.md          # Denne filen
```

## 🎮 Hvordan Spille

### Kontroller

- **Pil Opp** / **W** - Beveg opp
- **Pil Ned** / **S** - Beveg ned
- **Pil Venstre** / **A** - Beveg venstre
- **Pil Høyre** / **D** - Beveg høyre
- **Mellomrom** - Pause/fortsett

### Spillregler

1. Bruk piltastene til å styre slangen
2. Spis den røde maten for å vokse og få poeng
3. Unngå å treffe vegger eller deg selv
4. Spillet blir raskere jo flere poeng du får
5. Prøv å slå high score!

## 🎨 Tilpasse Design

### Via Web-grensesnittet

1. Klikk på **"Endre Tema"**-knappen
2. Bruk fargevelgerne til å endre:
   - Slange farge
   - Mat farge
   - Bakgrunn
   - Overflate
   - Primærfarge
3. Klikk **"Lagre Tema"**
4. Siden laster på nytt med dine nye farger

### Via config.json

Du kan også manuelt redigere `config.json`:

```json
{
  "theme": {
    "name": "custom",
    "colors": {
      "primary": "#FF6B6B",
      "secondary": "#4ECDC4",
      "background": "#1a1a2e",
      "surface": "#16213e",
      "text": "#eaeaea",
      "textSecondary": "#a0a0a0",
      "snake": "#FFD93D",
      "food": "#FF6B6B",
      "grid": "#0f3460",
      "gameOver": "#f44336"
    }
  },
  "game": {
    "gridSize": 20,
    "initialSpeed": 150,
    "speedIncrease": 5
  }
}
```

## 🏆 High Score System

### Hvordan det fungerer

1. Når spillet er over, skriv inn navnet ditt
2. Klikk **"Lagre Poengsum"**
3. Din score lagres i `scores.json`
4. Topp 10 scores vises i sidepanelet

### Database-struktur (scores.json)

```json
{
  "highScores": [
    {
      "name": "Spillernavn",
      "score": 42,
      "date": "2025-12-23 10:30:45"
    }
  ]
}
```

## 🔧 API Endepunkter

### GET Requests

- `api.php?action=getScores` - Hent alle high scores
- `api.php?action=getConfig` - Hent konfigurasjon

### POST Requests

**Lagre Score:**
```javascript
fetch('api.php?action=saveScore', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        name: 'Spillernavn',
        score: 42
    })
});
```

**Oppdater Config:**
```javascript
fetch('api.php?action=updateConfig', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(configObject)
});
```

## 🛠️ Teknologier

- **Frontend:**
  - HTML5 Canvas for spill-grafikk
  - Vanilla JavaScript (ES6+)
  - CSS3 med CSS Variables for temaing
  - Responsive design med Grid og Flexbox

- **Backend:**
  - PHP 7.4+
  - JSON flat file database
  - RESTful API-design

## 🎯 Spillfunksjoner

### Nåværende Funksjoner
- ✅ Basis Snake-spillmekanikk
- ✅ Score-system
- ✅ High score leaderboard
- ✅ Tilpassbare farger
- ✅ Pause-funksjon
- ✅ Progressive hastighetsvekst
- ✅ Responsivt design

### Mulige Utvidelser
- ⬜ Vanskelighetsgrader (Easy, Medium, Hard)
- ⬜ Power-ups og spesialmat
- ⬜ Forskjellige spillmodus (Classic, Timed, Endless)
- ⬜ Lyd-effekter og bakgrunnsmusikk
- ⬜ Multiplayer via WebSockets
- ⬜ Achievements system
- ⬜ Brukerkontoer med innlogging

## 🐛 Feilsøking

### Scores lagres ikke

Sjekk at PHP har skrive-tilgang:
```bash
chmod 666 scores.json
```

### Tema-endringer lagres ikke

Sjekk at PHP har skrive-tilgang:
```bash
chmod 666 config.json
```

### Spillet vises ikke

1. Sjekk at JavaScript er aktivert i nettleseren
2. Åpne Developer Console (F12) for å se feilmeldinger
3. Sjekk at alle filer er i samme mappe

### API-feil

Sjekk at `api.php` er tilgjengelig:
```bash
curl http://localhost:8000/api.php?action=getScores
```

## 📄 Lisens

Dette prosjektet er laget for læring og demonstrasjon. Du står fritt til å bruke, modifisere og dele det.

## 👨‍💻 Utvikling

### Kode-struktur

**game.js** inneholder `SnakeGame`-klassen med:
- `reset()` - Tilbakestill spillet
- `start()` - Start nytt spill
- `pause()` - Pause/fortsett
- `gameLoop()` - Hovedløkke
- `update()` - Oppdater spilltilstand
- `draw()` - Tegn på canvas
- `placeFood()` - Plasser ny mat
- `changeDirection()` - Endre retning
- `endGame()` - Avslutt spill

**api.php** inneholder funksjoner for:
- `getScores()` - Hent scores fra JSON
- `saveScore()` - Lagre ny score
- `getConfig()` - Hent konfigurasjon
- `updateConfig()` - Oppdater konfigurasjon

## 🙏 Takk

Laget med ❤️ for å demonstrere moderne web-utvikling med PHP og vanilla JavaScript.

## 📞 Support

For spørsmål eller problemer, sjekk:
1. Developer Console i nettleseren (F12)
2. PHP error logs
3. File permissions på JSON-filene

Lykke til med spilling! 🎮🐍

