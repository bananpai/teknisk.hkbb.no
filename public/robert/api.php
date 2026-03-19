<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$scoresFile = 'scores.json';
$configFile = 'config.json';

// Initialize scores file if it doesn't exist
if (!file_exists($scoresFile)) {
    file_put_contents($scoresFile, json_encode(['highScores' => []]));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'getScores':
        getScores($scoresFile);
        break;
    
    case 'saveScore':
        saveScore($scoresFile);
        break;
    
    case 'getConfig':
        getConfig($configFile);
        break;
    
    case 'updateConfig':
        updateConfig($configFile);
        break;
    
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}

function getScores($file) {
    $data = json_decode(file_get_contents($file), true);
    $scores = $data['highScores'] ?? [];
    
    // Sort by score descending
    usort($scores, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    // Return top 10
    echo json_encode(['scores' => array_slice($scores, 0, 10)]);
}

function saveScore($file) {
    $input = json_decode(file_get_contents('php://input'), true);
    $playerName = htmlspecialchars($input['name'] ?? 'Anonymous');
    $score = intval($input['score'] ?? 0);
    
    if ($score <= 0) {
        echo json_encode(['error' => 'Invalid score']);
        return;
    }
    
    $data = json_decode(file_get_contents($file), true);
    $scores = $data['highScores'] ?? [];
    
    $scores[] = [
        'name' => $playerName,
        'score' => $score,
        'date' => date('Y-m-d H:i:s')
    ];
    
    // Keep only top 50 scores
    usort($scores, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    $scores = array_slice($scores, 0, 50);
    
    $data['highScores'] = $scores;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    
    echo json_encode(['success' => true, 'message' => 'Score saved!']);
}

function getConfig($file) {
    if (!file_exists($file)) {
        echo json_encode(['error' => 'Config file not found']);
        return;
    }
    
    $config = json_decode(file_get_contents($file), true);
    echo json_encode($config);
}

function updateConfig($file) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['error' => 'Invalid config data']);
        return;
    }
    
    file_put_contents($file, json_encode($input, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'message' => 'Config updated!']);
}
?>

