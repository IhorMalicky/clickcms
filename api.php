<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'database.php';

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Get the JSON data from the request
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// Validate tracking code
if (!isset($data['tracking_code']) || empty($data['tracking_code'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing tracking code']);
    exit();
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get website ID from tracking code
$stmt = $db->prepare("SELECT id FROM websites WHERE tracking_code = ?");
$stmt->execute([$data['tracking_code']]);
$website = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$website) {
    http_response_code(404);
    echo json_encode(['error' => 'Invalid tracking code']);
    exit();
}

$website_id = $website['id'];
$visitor_id = isset($data['visitor_id']) ? $data['visitor_id'] : null;
$event_type = isset($data['event_type']) ? $data['event_type'] : 'pageview';

// Process based on event type
switch ($event_type) {
    case 'pageview':
        handlePageView($db, $website_id, $data);
        break;
    case 'session_update':
        updateSession($db, $website_id, $data);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid event type']);
        exit();
}

// Return success response
echo json_encode(['success' => true]);

// Function to handle page view events
function handlePageView($db, $website_id, $data) {
    // Check if this is a new visitor
    $visitor_id = isset($data['visitor_id']) ? $data['visitor_id'] : null;
    $visitor_db_id = null;
    
    if (!$visitor_id) {
        // New visitor - create visitor record
        $visitor_id = generateVisitorId();
        
        $stmt = $db->prepare("
            INSERT INTO visitors (
                website_id, 
                visitor_id, 
                ip_address, 
                referrer, 
                utm_source, 
                utm_medium, 
                utm_campaign, 
                utm_term, 
                utm_content, 
                user_agent, 
                device, 
                operating_system, 
                browser
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $website_id,
            $visitor_id,
            $_SERVER['REMOTE_ADDR'],
            $data['referrer'] ?? null,
            $data['utm_source'] ?? null,
            $data['utm_medium'] ?? null,
            $data['utm_campaign'] ?? null,
            $data['utm_term'] ?? null,
            $data['utm_content'] ?? null,
            $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
            $data['device'] ?? null,
            $data['operating_system'] ?? null,
            $data['browser'] ?? null
        ]);
        
        $visitor_db_id = $db->lastInsertId();
        
        // Create a new session
        $stmt = $db->prepare("
            INSERT INTO sessions (
                visitor_id, 
                website_id, 
                created_at
            ) VALUES (?, ?, NOW())
        ");
        
        $stmt->execute([
            $visitor_db_id,
            $website_id
        ]);
    } else {
        // Existing visitor - get visitor ID from database
        $stmt = $db->prepare("
            SELECT id FROM visitors 
            WHERE website_id = ? AND visitor_id = ?
        ");
        
        $stmt->execute([
            $website_id,
            $visitor_id
        ]);
        
        $visitor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($visitor) {
            $visitor_db_id = $visitor['id'];
        } else {
            // If visitor ID doesn't exist in our system, create a new one
            $stmt = $db->prepare("
                INSERT INTO visitors (
                    website_id, 
                    visitor_id, 
                    ip_address, 
                    referrer, 
                    utm_source, 
                    utm_medium, 
                    utm_campaign, 
                    utm_term, 
                    utm_content, 
                    user_agent, 
                    device, 
                    operating_system, 
                    browser
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $website_id,
                $visitor_id,
                $_SERVER['REMOTE_ADDR'],
                $data['referrer'] ?? null,
                $data['utm_source'] ?? null,
                $data['utm_medium'] ?? null,
                $data['utm_campaign'] ?? null,
                $data['utm_term'] ?? null,
                $data['utm_content'] ?? null,
                $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                $data['device'] ?? null,
                $data['operating_system'] ?? null,
                $data['browser'] ?? null
            ]);
            
            $visitor_db_id = $db->lastInsertId();
            
            // Create a new session
            $stmt = $db->prepare("
                INSERT INTO sessions (
                    visitor_id, 
                    website_id, 
                    created_at
                ) VALUES (?, ?, NOW())
            ");
            
            $stmt->execute([
                $visitor_db_id,
                $website_id
            ]);
        }
    }
    
    // Record page view
    $stmt = $db->prepare("
        INSERT INTO page_views (
            visitor_id, 
            website_id, 
            page_url, 
            page_title, 
            time_on_page, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $visitor_db_id,
        $website_id,
        $data['page_url'] ?? null,
        $data['page_title'] ?? null,
        $data['time_on_page'] ?? 0
    ]);
    
    // Return visitor ID for client-side storage
    return [
        'visitor_id' => $visitor_id
    ];
}

// Function to update session information
function updateSession($db, $website_id, $data) {
    $visitor_id = $data['visitor_id'];
    $session_duration = $data['session_duration'] ?? 0;
    
    // Get database visitor ID
    $stmt = $db->prepare("
        SELECT id FROM visitors 
        WHERE website_id = ? AND visitor_id = ?
    ");
    
    $stmt->execute([
        $website_id,
        $visitor_id
    ]);
    
    $visitor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$visitor) {
        return;
    }
    
    $visitor_db_id = $visitor['id'];
    
    // Update the latest session
    $stmt = $db->prepare("
        UPDATE sessions 
        SET session_duration = ?, 
            ended_at = NOW() 
        WHERE visitor_id = ? 
        AND website_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    
    $stmt->execute([
        $session_duration,
        $visitor_db_id,
        $website_id
    ]);
}

// Generate a unique visitor ID
function generateVisitorId() {
    return uniqid('', true) . '-' . bin2hex(random_bytes(8));
}