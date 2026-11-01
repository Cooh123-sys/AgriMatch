<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ---------- SHARED SMTP CREDENTIALS ----------
define('SMTP_EMAIL',    'makinafrezer@gmail.com');   // <-- your Gmail address
define('SMTP_APP_PASS', 'ejdhjxsrmmpvdnkh');

// ---------- ADMIN NOTIFICATION RECIPIENT ----------
define('ADMIN_EMAIL', 'makinafrezer@gmail.com');
define('ADMIN_NAME',  'AgriMatch Admin');


/**
 * Low-level helper: configures and returns a ready-to-send PHPMailer instance.
 */
function buildMailer($toEmail, $toName, $subject, $htmlBody) {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_EMAIL;
    $mail->Password   = SMTP_APP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom(SMTP_EMAIL, 'AgriMatch');
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;

    return $mail;
}


/**
 * Account status email to the farmer/buyer AND a matching notification to the admin.
 * $status: 'pending', 'verified', or 'rejected'
 */
function sendStatusEmail($toEmail, $toName, $role, $status) {

    $subject = "AgriMatch Account " . ucfirst($status);

    if ($status === 'verified') {
        $userBody = "
            <h3>Hello {$toName},</h3>
            <p>Congratulations! Your <strong>{$role}</strong> account on <strong>AgriMatch</strong> has been verified.</p>
            <p>You can now log in and start using the platform.</p>
            <p><a href='http://localhost/AgriMatch/auth/login.php'>Click here to log in</a></p>
            <br>
            <p>Regards,<br>AgriMatch Admin Team</p>
        ";
    } elseif ($status === 'rejected') {
        $userBody = "
            <h3>Hello {$toName},</h3>
            <p>We regret to inform you that your <strong>{$role}</strong> account registration on
            <strong>AgriMatch</strong> has been rejected after review of your submitted documents.</p>
            <p>If you believe this is a mistake, please contact the administrator or register again
            with correct documentation.</p>
            <br>
            <p>Regards,<br>AgriMatch Admin Team</p>
        ";
    } else { // pending
        $userBody = "
            <h3>Hello {$toName},</h3>
            <p>Thank you for registering as a <strong>{$role}</strong> on <strong>AgriMatch</strong>.</p>
            <p>Your account and submitted documents are now <strong>pending review</strong> by our admin team.
            You will receive another email once your account has been verified or if further action is needed.</p>
            <br>
            <p>Regards,<br>AgriMatch Admin Team</p>
        ";
    }

    $userSent = false;
    try {
        $mail = buildMailer($toEmail, $toName, $subject, $userBody);
        $mail->send();
        $userSent = true;
    } catch (Exception $e) {
        error_log("User email failed to send to {$toEmail}: " . $e->getMessage());
    }

    $adminSubject = "AgriMatch: {$role} account " . strtolower($status) . " — {$toName}";

    if ($status === 'pending') {
        $adminBody = "
            <h3>New {$role} registration</h3>
            <p><strong>{$toName}</strong> ({$toEmail}) has just registered as a <strong>{$role}</strong>
            and their account is now <strong>pending your review</strong>.</p>
            <p>Please log in to the admin dashboard to view their submitted documents and approve or reject the account.</p>
            <p><a href='http://localhost/AgriMatch/dashboard.php'>Go to Admin Dashboard</a></p>
        ";
    } elseif ($status === 'verified') {
        $adminBody = "
            <h3>Account Verified</h3>
            <p>You approved the <strong>{$role}</strong> account belonging to
            <strong>{$toName}</strong> ({$toEmail}). A confirmation email has been sent to them.</p>
        ";
    } else {
        $adminBody = "
            <h3>Account Rejected</h3>
            <p>You rejected the <strong>{$role}</strong> account belonging to
            <strong>{$toName}</strong> ({$toEmail}). A notification email has been sent to them.</p>
        ";
    }

    try {
        $adminMail = buildMailer(ADMIN_EMAIL, ADMIN_NAME, $adminSubject, $adminBody);
        $adminMail->send();
    } catch (Exception $e) {
        error_log("Admin notification email failed to send: " . $e->getMessage());
    }

    return $userSent;
}


/**
 * Match notification email (works in both directions).
 *
 * $recipientRole = role of the PERSON RECEIVING this email ('farmer' or 'buyer')
 * $event         = 'requested' | 'accepted' | 'rejected'
 * $otherPartyName/Phone/Email = the other side's details. Phone/email are only passed for 'accepted'.
 */
function sendMatchEmail($toEmail, $toName, $recipientRole, $event, $cropType, $otherPartyName = null, $otherPartyPhone = null, $otherPartyEmail = null) {

    $name       = htmlspecialchars($toName);
    $crop       = htmlspecialchars($cropType);
    $other      = $otherPartyName !== null ? htmlspecialchars($otherPartyName) : null;
    $otherLabel = $recipientRole === 'farmer' ? 'buyer' : 'farmer';
    $pageUrl    = $recipientRole === 'farmer'
        ? 'http://localhost/AgriMatch/farmer/matches.php'
        : 'http://localhost/AgriMatch/buyer/matched_farmers.php';

    $subject = "AgriMatch: Match " . ucfirst($event) . " — {$cropType}";

    if ($event === 'requested') {
        if ($recipientRole === 'farmer') {
            $who  = $other ? "<strong>{$other}</strong> (a buyer)" : "A buyer";
            $line = "{$who} has requested a match with your <strong>{$crop}</strong> produce on AgriMatch.";
        } else {
            $who  = $other ? "<strong>{$other}</strong> (a farmer)" : "A farmer";
            $line = "{$who} has offered to supply <strong>{$crop}</strong> for your demand on AgriMatch.";
        }
        $body = "
            <h3>Hello {$name},</h3>
            <p>{$line}</p>
            <p>Log in to review the request and accept or decline it. Contact details are shared with both sides once a match is accepted.</p>
            <p><a href='{$pageUrl}'>View the request</a></p>
            <br>
            <p>Regards,<br>AgriMatch Team</p>
        ";

    } elseif ($event === 'accepted') {
        $contactBlock = '';
        if ($other) {
            $label = ucfirst($otherLabel);
            $phone = htmlspecialchars((string) $otherPartyPhone);
            $mail  = htmlspecialchars((string) $otherPartyEmail);
            $contactBlock = "
                <p><strong>{$label} Contact Details:</strong><br>
                Name: {$other}<br>
                Phone: {$phone}<br>
                Email: {$mail}</p>
            ";
        }
        $body = "
            <h3>Hello {$name},</h3>
            <p>Good news! Your <strong>{$crop}</strong> match on AgriMatch has been <strong>accepted</strong>.</p>
            {$contactBlock}
            <p>You can now contact each other directly to arrange the transaction.</p>
            <br>
            <p>Regards,<br>AgriMatch Team</p>
        ";

    } else { // rejected
        $body = "
            <h3>Hello {$name},</h3>
            <p>Unfortunately, your match request for <strong>{$crop}</strong> was <strong>declined</strong> by the {$otherLabel}.</p>
            <p>Other matches may still be available. Log in to check.</p>
            <p><a href='{$pageUrl}'>Open AgriMatch</a></p>
            <br>
            <p>Regards,<br>AgriMatch Team</p>
        ";
    }

    try {
        $mail = buildMailer($toEmail, $toName, $subject, $body);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Match email failed to send to {$toEmail}: " . $e->getMessage());
        return false;
    }
}


/**
 * Email the OTHER party after someone creates a match request.
 * $listing / $demand come from createMatchRequest() in match_engine.php.
 */
function notifyMatchRequested($listing, $demand, $actorRole) {
    if ($actorRole === 'farmer') {
        // Farmer requested -> tell the buyer
        sendMatchEmail($demand['buyer_email'], $demand['buyer_name'], 'buyer', 'requested',
            $listing['crop_type'], $listing['farmer_name']);
    } else {
        // Buyer requested -> tell the farmer
        sendMatchEmail($listing['farmer_email'], $listing['farmer_name'], 'farmer', 'requested',
            $listing['crop_type'], $demand['buyer_name']);
    }
}


/**
 * Emails after a request is accepted or declined.
 * $result comes from respondToMatch() in match_engine.php.
 */
function notifyMatchResponse($result) {
    $listing = $result['listing'];
    $demand  = $result['demand'];
    $match   = $result['match'];
    $crop    = $listing['crop_type'];

    if ($result['action'] === 'accept') {
        // Both sides get the other's contact details
        sendMatchEmail($listing['farmer_email'], $listing['farmer_name'], 'farmer', 'accepted', $crop,
            $demand['buyer_name'], $demand['buyer_phone'], $demand['buyer_email']);
        sendMatchEmail($demand['buyer_email'], $demand['buyer_name'], 'buyer', 'accepted', $crop,
            $listing['farmer_name'], $listing['farmer_phone'], $listing['farmer_email']);
    } else {
        // Only the party who made the request is told it was declined
        if ($match['requested_by'] === 'buyer') {
            sendMatchEmail($demand['buyer_email'], $demand['buyer_name'], 'buyer', 'rejected', $crop,
                $listing['farmer_name']);
        } else {
            sendMatchEmail($listing['farmer_email'], $listing['farmer_name'], 'farmer', 'rejected', $crop,
                $demand['buyer_name']);
        }
    }
}