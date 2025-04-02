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
error_log('Початок обробки login.php - ' . date('Y-m-d H:i:s'));

// Запобігання кешуванню
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Включаємо буферизацію виводу
ob_start();

// Запуск сесії 
session_start();
error_log('Сесія запущена. Session ID: ' . session_id());

// Перевірка, чи користувач вже увійшов
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    error_log('Користувач вже авторизований (ID: ' . $_SESSION['user_id'] . '). Перенаправлення на index.php');
    // Переконуємося, що сесія зберігається перед перенаправленням
    session_write_close();
    header('Location: index.php');
    ob_end_clean(); // Очищаємо буфер перед перенаправленням
    exit();
}

// Підключення до бази даних
try {
    require_once 'database.php';
    $database = new Database();
    $db = $database->getConnection();
    error_log('Підключення до бази даних встановлено успішно');
} catch (Exception $e) {
    error_log('ПОМИЛКА при підключенні до бази даних: ' . $e->getMessage());
    die('Помилка підключення до бази даних. Перевірте логи.');
}

$error = '';

// Обробка форми входу
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log('Отримано POST запит на вхід');
    
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    error_log('Спроба входу з паролем: ' . substr($password, 0, 1) . '****');
    
    // Перевірка на фіксований пароль 5555
    if ($password === '5555') {
        error_log('Пароль правильний');
        
        try {
            // Перевірка наявності користувача з ID=1
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id = 1");
            $stmt->execute();
            $userExists = (int)$stmt->fetchColumn() > 0;
            
            if (!$userExists) {
                error_log('Користувач з ID=1 не існує. Створюємо нового користувача.');
                // Створюємо користувача з ID=1
                $stmt = $db->prepare("INSERT INTO users (id, username) VALUES (1, 'admin')");
                $result = $stmt->execute();
                error_log('Результат створення користувача: ' . ($result ? 'успішно' : 'невдало'));
            } else {
                error_log('Користувач з ID=1 вже існує в базі даних');
            }
            
            // Встановлюємо сесію
            $_SESSION['user_id'] = 1;
            $_SESSION['username'] = 'admin';
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            
            error_log('Сесійні дані встановлено: ' . print_r($_SESSION, true));
            
            // Встановлюємо cookie сесії вручну для підвищення надійності
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), time() + 86400,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
            
            // Також встановлюємо резервні cookie для додаткової надійності
            setcookie('user_auth', '1', time() + 86400, '/', '', false, true);
            
            // Зберігаємо сесію перед перенаправленням
            session_write_close();
            
            // Перезапускаємо сесію, щоб переконатися що зміни збережені
            session_start();
            
            // Перевіряємо, чи встановлені дані сесії
            error_log('Перевірка сесійних даних перед перенаправленням: ' . print_r($_SESSION, true));
            
            // Перенаправляємо на головну сторінку
            error_log('Перенаправлення на index.php');
            header('Location: index.php');
            ob_end_clean(); // Очищаємо буфер перед перенаправленням
            exit();
        } catch (Exception $e) {
            error_log('ПОМИЛКА при роботі з базою даних: ' . $e->getMessage());
            $error = 'Сталася помилка при вході. Будь ласка, спробуйте пізніше.';
        }
    } else {
        error_log('Невірний пароль');
        $error = 'Невірний пароль. Спробуйте ще раз.';
    }
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вхід до системи</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-container {
            background-color: white;
            padding: 30px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 300px;
            max-width: 100%;
        }
        h1 {
            text-align: center;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="password"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #45a049;
        }
        .error {
            color: red;
            margin-bottom: 15px;
            text-align: center;
        }
        .debug-info {
            margin-top: 15px;
            padding: 10px;
            background-color: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Вхід до системи</h1>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" required autofocus>
                <small style="color: #666;">Використовуйте пароль: 5555</small>
            </div>
            <button type="submit">Увійти</button>
        </form>
        
        <div style="margin-top: 15px; text-align: center;">
            <a href="index.php">Перейти на головну сторінку вручну</a>
        </div>
        
        <div class="debug-info">
            <strong>Час запиту:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
            <strong>Session ID:</strong> <?php echo session_id(); ?><br>
            <strong>PHP Version:</strong> <?php echo phpversion(); ?>
        </div>
    </div>
    
    <script>
        // Відправка форми при натисканні Enter
        document.getElementById('password').addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                document.querySelector('button[type="submit"]').click();
            }
        });
    </script>
</body>
</html>
<?php
// Завершуємо буферизацію та надсилаємо вміст
ob_end_flush();
?>