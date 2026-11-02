
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection error.");
}

// GET LOGGED-IN USER
$accountId = (int)(
    $_SESSION['user_id']
    ?? $_SESSION['resident_id']
    ?? $_SESSION['household_id']
    ?? $_SESSION['id']
    ?? 0
);

if ($accountId <= 0) {
    header("Location: ../login.php");
    exit;
}

// GET ECO POINTS BALANCE
function getBalance($conn, $accountId) {

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(points), 0) AS balance
        FROM eco_points
        WHERE user_id = ?
    ");

    if (!$stmt) {
        die("Database error: " . $conn->error);
    }

    $stmt->bind_param("i", $accountId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return (int)$row['balance'];
}

// AVAILABLE REWARDS
$rewards = [
    10 => [
        'name' => '10% Reward',
        'description' => 'Redeem 10 points for this reward.'
    ],

    30 => [
        'name' => '30% Reward',
        'description' => 'Redeem 30 points for this reward.'
    ],

    50 => [
        'name' => '50% Reward',
        'description' => 'Redeem 50 points for this reward.'
    ]
];

// SECURITY TOKEN
if (empty($_SESSION['redeem_token'])) {
    $_SESSION['redeem_token'] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "";

// PROCESS REDEMPTION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['redeem_token'] ?? '';
    $pointsToRedeem = (int)($_POST['points'] ?? 0);

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['redeem_token'], $token)
    ) {

        $message = "Invalid request. Please refresh the page.";
        $messageType = "error";

    } elseif (!isset($rewards[$pointsToRedeem])) {

        $message = "Invalid reward selected.";
        $messageType = "error";

    } else {

        try {

            $conn->begin_transaction();

            // CHECK AVAILABLE POINTS
            $balance = getBalance($conn, $accountId);

            if ($balance < $pointsToRedeem) {
                throw new Exception(
                    "Not enough Eco Points to redeem this reward."
                );
            }

            // DEDUCT POINTS
            $deductPoints = -$pointsToRedeem;

            $description =
                $rewards[$pointsToRedeem]['name'] . " claimed";

            // SAVE TO ECO POINTS TABLE
            // collection_id is NULL
            $stmt = $conn->prepare("
                INSERT INTO eco_points
                (user_id, collection_id, points, description, created_at)
                VALUES (?, NULL, ?, ?, NOW())
            ");

            if (!$stmt) {
                throw new Exception($conn->error);
            }

            $stmt->bind_param(
                "iis",
                $accountId,
                $deductPoints,
                $description
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $stmt->close();

            // COMMIT DATABASE
            $conn->commit();

            // NEW TOKEN
            $_SESSION['redeem_token'] =
                bin2hex(random_bytes(32));

            header("Location: redeem.php?success=1");
            exit;

        } catch (Exception $e) {

            $conn->rollback();

            $message = $e->getMessage();
            $messageType = "error";
        }
    }
}

// SUCCESS MESSAGE
if (isset($_GET['success'])) {
    $message = "Your reward has been successfully redeemed!";
    $messageType = "success";
}

// GET UPDATED BALANCE
$balance = getBalance($conn, $accountId);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Redeem Your Rewards</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f5f5f5;
    color: #333;
}

.header {
    background: #198754;
    color: white;
    padding: 18px 25px;

    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header h2 {
    margin: 0;
    font-size: 22px;
}

.header a {
    color: white;
    text-decoration: none;
}

.container {
    max-width: 1070px;
    margin: 30px auto;
    padding: 0 15px;
}

.intro {
    margin-bottom: 25px;
}

.intro p {
    color: #666;
}

.balance {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 22px;
    margin-bottom: 25px;
}

.balance p {
    color: #666;
    margin: 0;
}

.balance h2 {
    color: #198754;
    margin-bottom: 0;
    font-size: 30px;
}

.reward-grid {
    display: grid;

    grid-template-columns: repeat(
        auto-fit,
        minmax(220px, 1fr)
    );

    gap: 18px;
}

.reward-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 25px 22px;
    text-align: center;
}

.reward-card h3 {
    color: #198754;
    margin-bottom: 10px;
}

.reward-card p {
    color: #666;
    font-size: 14px;
    min-height: 38px;
}

.points {
    font-size: 21px;
    font-weight: bold;
    margin: 22px 0;
}

.redeem-btn {
    width: 100%;
    border: none;
    border-radius: 4px;
    padding: 12px;
    background: #198754;
    color: white;
    font-size: 14px;
    cursor: pointer;
}

.redeem-btn:hover {
    background: #146c43;
}

.redeem-btn:disabled {
    background: #aaa;
    cursor: not-allowed;
}

.message {
    padding: 13px;
    margin-bottom: 20px;
    border-radius: 4px;
    text-align: center;
}

.success {
    background: #d8f3df;
    color: #176b3a;
}

.error {
    background: #ffe0e0;
    color: #a12626;
}

.note {
    margin-top: 25px;
    padding: 15px;
    background: #fff8df;
    color: #776321;
    font-size: 13px;
    line-height: 1.5;
}

.back {
    display: inline-block;
    margin-top: 25px;
    color: #198754;
    text-decoration: none;
}

.back:hover {
    text-decoration: underline;
}

@media(max-width: 500px) {

    .header {
        padding: 18px;
    }

    .header h2 {
        font-size: 18px;
    }

}

</style>

</head>

<body>

<!-- HEADER -->
<div class="header">

    <h2>Eco Points</h2>

    <a href="eco_points.php">
        Back to Eco Points
    </a>

</div>

<div class="container">

    <!-- TITLE -->
    <div class="intro">

        <h1>Redeem Your Rewards</h1>

        <p>
            Choose a reward using your available Eco Points.
        </p>

    </div>

    <!-- MESSAGE -->
    <?php if (!empty($message)): ?>

        <div class="message <?= htmlspecialchars($messageType) ?>">

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>

    <!-- AVAILABLE POINTS -->
    <div class="balance">

        <p>Your Available Eco Points</p>

        <h2>
            <?= number_format($balance) ?> Points
        </h2>

    </div>

    <!-- REWARD CARDS -->
    <div class="reward-grid">

        <?php foreach ($rewards as $cost => $reward): ?>

            <div class="reward-card">

                <h3>
                    <?= htmlspecialchars($reward['name']) ?>
                </h3>

                <p>
                    <?= htmlspecialchars($reward['description']) ?>
                </p>

                <div class="points">
                    <?= $cost ?> Points
                </div>

                <form
                    method="POST"
                    onsubmit="return confirm('Are you sure you want to redeem this reward?');"
                >

                    <input
                        type="hidden"
                        name="redeem_token"
                        value="<?= htmlspecialchars($_SESSION['redeem_token']) ?>"
                    >

                    <input
                        type="hidden"
                        name="points"
                        value="<?= $cost ?>"
                    >

                    <button
                        type="submit"
                        class="redeem-btn"
                        <?= $balance < $cost ? 'disabled' : '' ?>
                    >

                        <?= $balance < $cost
                            ? 'Not Enough Points'
                            : 'Redeem Now'
                        ?>

                    </button>

                </form>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- NOTE -->
    <div class="note">

        <strong>Note:</strong>

        Redeeming deducts Eco Points from your balance
        and records the reward claim in your points history.

        It does not yet apply a discount to payments
        or subscriptions.

    </div>

    <!-- BACK BUTTON -->
    <a href="eco_points.php" class="back">
        Back to Eco Points
    </a>

</div>

</body>
</html>
