<?php

# This code was written while following the tutorial provided by Daniel Cremers on youtube titled
# "Godot to and from MySQL database tutorial"
# URL: https://www.youtube.com/watch?v=AO6GrHdzUeU&list=PL_fJb4SNBWQkfNEvWcflnAF_OUqORVVBC&index=22


# Preflight check
if (isset($_SERVER["HTTP_ORIGIN"])) {
    header("Access-Control-Allow-Origin: *"); # Allow all external connections
    header("Access-Control-Max-Age: 60"); # Make sure connection stays open for at least 60 seconds

    # Check if a site is requesting access to the site

    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        header("Access-Control-Allow-Methods: POST, OPTIONS"); # Allow only these requests
        header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Origin, cache-control, cnonce, hash");
        http_response_code(200); # Report that they are good to make their request now
        die; # Quit here untile they send a followup
    }
}


# Lets stop anything other than POST request here itself
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405); # Report that they were denied access
    die;
}

function print_response($dictionary = [], $error = "none")
{
    $string = "";

    error_log($_REQUEST["command"]);
    # Convert our dictionary into JSON string
    $string = "{\"error\" : \"$error\", \"command\" : \"$_REQUEST[command]\", \"response\" : " . json_encode($dictionary) . "}";
    error_log($string);

    # Print out our json to Godot!
    echo $string;
}

# Check that the user has permission to make a request and the request has not been tampered with 
function verify_nonce($pdo, $secret = "1234567890")
{
    # Make sure they send over a CNONCE
    if (!isset($_SERVER["HTTP_CNONCE"])) {
        print_response([], "invalid_nonce");
        return false;
    }

    # Make the request to pull the nonce from the server
    $template = "SELECT nonce FROM `nonces` WHERE ip_address = :ip";
    $sth = $pdo->prepare($template);
    $sth->execute(["ip" => $_SERVER["REMOTE_ADDR"]]);
    $data = $sth->fetchAll(PDO::FETCH_ASSOC);

    # Check that there was a nonce for this user on the server
    if (!isset($data) or sizeof($data) <= 0) {
        print_response([], "server_missing_nonce");
        return false;
    }

    # Delete the nonce off the server. Each is a one-use key
    $sth = $pdo->prepare("DELETE FROM `nonces` WHERE ip_address = :ip");
    $sth->execute(["ip" => $_SERVER["REMOTE_ADDR"]]);

    # Check the nonce hash to make sure it is valid
    $server_nonce = $data[0]['nonce'];

    if (hash('sha256', $server_nonce . $_SERVER["HTTP_CNONCE"] . file_get_contents("php://input") . $secret) != $_SERVER["HTTP_HASH"]) {
        print_response([], "invalid_nonce_or_hash");
        return false;
    }

    # At this point, all is good!
    return true;
}

if (!isset($_REQUEST['command']) or $_REQUEST['command'] === null) {
    echo "{\"error\" : \"missing_command\", \"response\" : {}}";
    # print_response([], "missing_command");
    die;
}

if (!isset($_REQUEST['data']) or $_REQUEST['data'] === null) {
    print_response([], "missing_data");
    die;
}


# Set connection properties for our database

$sql_host = getenv('DB_HOST');
$sql_db = getenv('DB_NAME');
$sql_username = getenv('DB_USER');
$sql_password = getenv('DB_PASSWORD');

# Set up our data in a format that PDO understands

$dsn = "mysql:dbname=$sql_db;host=$sql_host";
$pdo = null;

# Attempt to connect:
try {
    $pdo = new PDO($dsn, $sql_username, $sql_password);
} catch (\PDOException $e) {
    print_response([], "db_login_error " . $e->getMessage());
    die;
}

# Convert our Godot json string into a dictionary
$json = json_decode($_REQUEST['data'], true);

error_log("Raw command: " . $_REQUEST['command']);
error_log("Raw data: " . $_REQUEST['data']);


# Check that the json was valid
if ($json === null) {
    print_response([], "invalid_json");
    die;
}

# Execute our Godot commands
switch ($_REQUEST['command']) {

    # Generate a single-use nonce for our user and return it to Godot
    case 'get_nonce':
        # Generate random bytes that we can hash
        $bytes = random_bytes(32);
        $nonce = hash('sha256', $bytes);

        # Form our SQL template
        $template = "INSERT INTO `nonces` (ip_address, nonce) VALUES (:ip, :nonce) ON DUPLICATE KEY UPDATE nonce = :nonce_update";

        # Prepare and send via PDO
        $sth = $pdo->prepare($template);
        $sth->execute(["ip" => $_SERVER["REMOTE_ADDR"], "nonce" => $nonce, "nonce_update" => $nonce]);

        # Send the nonce back to Godot
        print_response(["nonce" => $nonce]);
        die;
        break;

    case 'get_scores':

        # Check if we have a valid nonce
        if (!verify_nonce($pdo)) {
            die;
        }

        # Determine which range of scores we want
        $score_number = 10;

        if (isset($json['score_number'])) {
            $score_number = max(1, (int) $json['score_number']);
        }

        # Selection template from the database
        $template = "SELECT username, score FROM `highscores` ORDER BY score DESC LIMIT :score_number";

        # Prepare the send the request to the DB
        $sth = $pdo->prepare($template);
        $sth->bindValue('score_number', $score_number, PDO::PARAM_INT);
        $sth->execute();

        # Grab all the resulting data from the request
        $data = $sth->fetchAll(PDO::FETCH_ASSOC);

        # Add the size of our result from the database
        $data["size"] = sizeof($data);

        print_response($data);
        die;
        break;

    # Add a score to our table
    case 'add_score':

        # Check if we have a valid nonce
        if (!verify_nonce($pdo)) {
            die;
        }

        # Check that we were given a score and username
        if (!isset($json['score'])) {
            print_response([], "missing_score");
            die;
        }

        if (!isset($json['username'])) {
            print_response([], "missing_username");
            die;
        }

        # Make sure our username is under 24 characters
        $username = $json['username'];
        if (strlen($username) > 24) {
            $username = substr($username, 0, 24);
        }
        error_log("Username after assignment: " . $username);

        # Insert template for database
        $template = "INSERT INTO `highscores` (username, score) VALUES (:username, :score)";

        # Prepare and send the request to the DB
        $sth = $pdo->prepare($template);
        $sth->execute(["username" => $username, "score" => $json['score']]);

        error_log("Username: " . $json['username']);
        error_log("score: " . $json['score']);
        print_response();
        die;
        break;

    # Handle invalid requests
    default:
        print_response([], "invalid_command");
        die;
        break;
}
