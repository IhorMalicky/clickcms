<?php
// Налаштування сесії та запобігання загальним проблемам
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Встановіть 1, якщо використовуєте HTTPS
ini_set('session.cookie_lifetime', 86400); // 24 години
ini_set('session.gc_maxlifetime', 86400); // 24 години

// Налаштування логування
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');
ini_set('display_errors', 1); // Тимчасово включаємо відображення помилок
error_log('Початок обробки index.php - ' . date('Y-m-d H:i:s'));

// Запобігання кешуванню
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Включаємо буферизацію виводу
ob_start();

// Запуск сесії
session_start();
error_log('Сесія запущена. Session ID: ' . session_id());
error_log('SESSION дані: ' . print_r($_SESSION, true));

// Перевірка на резервні cookie, якщо сесія не встановлена
if ((!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) && isset($_COOKIE['user_auth']) && $_COOKIE['user_auth'] == '1') {
    error_log('Сесію не знайдено, але знайдено резервний cookie. Відновлюємо сесію.');
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'admin';
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    error_log('Сесію відновлено з резервного cookie');
}

// Перевірка автентифікації
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || $_SESSION['user_id'] <= 0) {
    error_log('Користувач не авторизований. Перенаправлення на login.php');
    // Зберігаємо сесію перед перенаправленням
    session_write_close();
    header('Location: login.php');
    ob_end_clean(); // Очищаємо буфер перед перенаправленням
    exit();
}

// Перевірка часу останньої активності
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 86400)) {
    error_log('Сесія застаріла. Перенаправлення на login.php');
    // Знищуємо всі дані сесії
    $_SESSION = [];
    
    // Знищуємо cookie сесії
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Знищуємо сесію
    session_destroy();
    
    // Перенаправляємо на сторінку входу
    header('Location: login.php');
    ob_end_clean();
    exit();
}

// Оновлюємо час останньої активності
$_SESSION['login_time'] = time();
$db = null;

// Ініціалізація підключення до бази даних
try {
    require_once 'database.php';
    $database = new Database();
    $db = $database->getConnection();
    error_log('Підключення до бази даних встановлено успішно');
} catch (Exception $e) {
    error_log('ПОМИЛКА при підключенні до бази даних: ' . $e->getMessage());
    die('Помилка підключення до бази даних. Будь ласка, перевірте логи.');
}

// Визначаємо структуру таблиці websites
$columnToUse = 'user_id'; // За замовчуванням
try {
    $checkTable = $db->query("DESCRIBE websites");
    $columns = [];
    while ($col = $checkTable->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $col['Field'];
    }
    error_log('Структура таблиці websites: ' . implode(', ', $columns));
    
    // Перевіряємо, яке поле використовувати для ідентифікатора користувача
    if (in_array('user_id', $columns)) {
        $columnToUse = 'user_id';
    } elseif (in_array('owner_id', $columns)) {
        $columnToUse = 'owner_id';
    } elseif (in_array('uid', $columns)) {
        $columnToUse = 'uid';
    } elseif (in_array('creator_id', $columns)) {
        $columnToUse = 'creator_id';
    } else {
        // Додаємо поле user_id, якщо воно відсутнє
        $db->exec("ALTER TABLE websites ADD COLUMN user_id INT NOT NULL");
        error_log('Додано поле user_id до таблиці websites');
        $columnToUse = 'user_id';
    }
    error_log('Використовуємо поле ' . $columnToUse . ' для ID користувача');
} catch (Exception $e) {
    error_log('ПОМИЛКА при перевірці структури таблиці: ' . $e->getMessage());
    $error_message = "Помилка при перевірці бази даних: " . $e->getMessage();
}

// Обробка додавання нового веб-сайту
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_website'])) {
    error_log('Отримано запит на додавання нового веб-сайту');
    
    $website_url = isset($_POST['website_url']) ? trim($_POST['website_url']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    
    error_log('Дані форми: URL=' . $website_url . ', Name=' . $name . ', user_id=' . $_SESSION['user_id']);
    
    if (!empty($website_url) && !empty($name)) {
        try {
            // Генеруємо код відстеження (випадковий MD5 хеш)
            $tracking_code = md5(uniqid(rand(), true));
            
            // Підготовка та виконання запиту з використанням визначеного поля
            $sql = "INSERT INTO websites (website_url, tracking_code, name, $columnToUse) VALUES (?, ?, ?, ?)";
            error_log('SQL запит: ' . $sql);
            
            $stmt = $db->prepare($sql);
            $user_id = (int)$_SESSION['user_id']; // Переконуємося, що user_id є числом
            
            error_log('Спроба виконати запит з URL=' . $website_url . ', tracking_code=' . $tracking_code . ', name=' . $name . ', ' . $columnToUse . '=' . $user_id);
            
            $result = $stmt->execute([$website_url, $tracking_code, $name, $user_id]);
            
            if ($result) {
                error_log('Новий веб-сайт успішно доданий: ' . $name . ' (' . $website_url . '). ID=' . $db->lastInsertId());
                // Зберігаємо сесію перед перенаправленням
                session_write_close();
                // Перенаправляємо для оновлення сторінки (з запобіганням кешування)
                header('Location: index.php?added=1&t=' . time());
                ob_end_flush();
                exit();
            } else {
                error_log('Помилка при додаванні веб-сайту. Код помилки: ' . $stmt->errorCode());
                error_log('Інформація про помилку: ' . print_r($stmt->errorInfo(), true));
                $error_message = "Помилка при додаванні веб-сайту. Будь ласка, спробуйте ще раз.";
            }
        } catch (Exception $e) {
            error_log('ПОМИЛКА при додаванні веб-сайту: ' . $e->getMessage());
            $error_message = "Помилка: " . $e->getMessage();
        }
    } else {
        error_log('Недійсні дані форми для додавання веб-сайту');
        $error_message = "Заповніть усі поля форми.";
    }
}

// Отримуємо веб-сайти для поточного користувача
try {
    // Використовуємо те ж саме поле для пошуку сайтів
    $sql = "SELECT * FROM websites WHERE $columnToUse = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$_SESSION['user_id']]);
    $websites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log('Отримано ' . count($websites) . ' веб-сайтів для користувача ID=' . $_SESSION['user_id']);
} catch (Exception $e) {
    error_log('ПОМИЛКА при отриманні веб-сайтів: ' . $e->getMessage());
    $websites = [];
}

// Функція для виходу
if (isset($_GET['logout'])) {
    error_log('Запит на вихід з системи');
    // Знищуємо всі дані сесії
    $_SESSION = [];
    
    // Знищуємо cookie сесії
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Знищуємо резервний cookie
    setcookie('user_auth', '', time() - 42000, '/', '', false, true);
    
    // Знищуємо сесію
    session_destroy();
    
    // Перенаправляємо на сторінку входу
    header('Location: login.php');
    ob_end_clean(); // Очищаємо буфер перед перенаправленням
    exit();
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Аналітична Платформа - Панель керування</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Аналітична Панель</h1>
                    <div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWebsiteModal">
                            Додати веб-сайт
                        </button>
                        <a href="index.php?logout=1" class="btn btn-outline-danger ms-2">Вийти</a>
                    </div>
                </div>
                
                <div class="alert alert-success">
                    Ви увійшли як: <?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Користувач'; ?>
                    <br>
                    <small>Session ID: <?php echo session_id(); ?></small>
                </div>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['added']) && $_GET['added'] == '1'): ?>
                    <div class="alert alert-success">
                        Веб-сайт успішно додано!
                    </div>
                <?php endif; ?>
                
                <?php if (empty($websites)): ?>
                    <div class="alert alert-info">
                        Ви ще не додали жодного веб-сайту. Натисніть кнопку "Додати веб-сайт", щоб розпочати.
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($websites as $website): ?>
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="card-title"><?= htmlspecialchars($website['name']) ?></h5>
                                        <h6 class="card-subtitle mb-2 text-muted"><?= htmlspecialchars($website['website_url']) ?></h6>
                                        
                                        <?php
                                        // Отримуємо базову статистику для цього веб-сайту
                                        try {
                                            $stmt = $db->prepare("
                                                SELECT 
                                                    COUNT(DISTINCT v.id) as visitors_count,
                                                    COUNT(p.id) as pageviews_count
                                                FROM websites w
                                                LEFT JOIN visitors v ON w.id = v.website_id
                                                LEFT JOIN page_views p ON w.id = p.website_id
                                                WHERE w.id = ?
                                                GROUP BY w.id
                                            ");
                                            $stmt->execute([$website['id']]);
                                            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                                            
                                            $visitors_count = $stats ? $stats['visitors_count'] : 0;
                                            $pageviews_count = $stats ? $stats['pageviews_count'] : 0;
                                        } catch (Exception $e) {
                                            error_log('ПОМИЛКА при отриманні статистики: ' . $e->getMessage());
                                            $visitors_count = 0;
                                            $pageviews_count = 0;
                                        }
                                        ?>
                                        
                                        <div class="row mt-3">
                                            <div class="col">
                                                <div class="border rounded p-3 text-center">
                                                    <h3><?= $visitors_count ?></h3>
                                                    <p class="mb-0">Відвідувачів</p>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div class="border rounded p-3 text-center">
                                                    <h3><?= $pageviews_count ?></h3>
                                                    <p class="mb-0">Переглядів</p>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <p class="mb-2"><strong>Код відстеження:</strong></p>
                                            <div class="bg-light p-2 rounded">
                                                <code>
                                                    &lt;script src="https://yourdomain.com/tracker.js?code=<?= $website['tracking_code'] ?>"&gt;&lt;/script&gt;
                                                </code>
                                                <button class="btn btn-sm btn-outline-secondary copy-btn" data-code="<?= $website['tracking_code'] ?>">
                                                    Копіювати
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <a href="stats.php?id=<?= $website['id'] ?>" class="btn btn-info">Переглянути детальну статистику</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Відображення інформації про сесію та структуру таблиці для діагностики -->
                <div class="mt-5 p-3 border rounded bg-light">
                    <h4>Діагностична інформація</h4>
                    <p><strong>Використовуване поле для ID користувача:</strong> <?php echo $columnToUse; ?></p>
                    <p><strong>Структура таблиці:</strong> <?php echo isset($columns) ? implode(', ', $columns) : 'Не визначено'; ?></p>
                    <pre><?php 
                    echo "Session user_id: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'не встановлено') . "\n";
                    echo "Session username: " . (isset($_SESSION['username']) ? $_SESSION['username'] : 'не встановлено') . "\n";
                    echo "POST дані: " . (isset($_POST) ? print_r($_POST, true) : 'немає') . "\n";
                    ?></pre>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Модальне вікно додавання веб-сайту -->
    <div class="modal fade" id="addWebsiteModal" tabindex="-1" aria-labelledby="addWebsiteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addWebsiteModalLabel">Додати новий веб-сайт</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" id="addWebsiteForm" action="index.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="website_url" class="form-label">URL веб-сайту</label>
                            <input type="url" class="form-control" id="website_url" name="website_url" required>
                        </div>
                        <div class="mb-3">
                            <label for="name" class="form-label">Назва веб-сайту</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                        <button type="submit" name="add_website" class="btn btn-primary">Додати веб-сайт</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Копіювання коду відстеження в буфер обміну
        document.querySelectorAll('.copy-btn').forEach(button => {
            button.addEventListener('click', function() {
                const code = this.getAttribute('data-code');
                const trackingCode = `<script src="https://yourdomain.com/tracker.js?code=${code}"><\/script>`;
                
                navigator.clipboard.writeText(trackingCode).then(() => {
                    this.textContent = 'Скопійовано!';
                    setTimeout(() => {
                        this.textContent = 'Копіювати';
                    }, 2000);
                });
            });
        });
        
        // Додатковий код для перевірки надсилання форми
        document.getElementById('addWebsiteForm').addEventListener('submit', function(e) {
            const url = document.getElementById('website_url').value;
            const name = document.getElementById('name').value;
            
            if (!url || !name) {
                e.preventDefault();
                alert('Будь ласка, заповніть всі поля форми.');
                return false;
            }
            
            console.log('Надсилання форми з URL: ' + url + ', назва: ' + name);
        });
    </script>
</body>
</html>
<?php
// Завершуємо буферизацію та надсилаємо вміст
ob_end_flush();
?>