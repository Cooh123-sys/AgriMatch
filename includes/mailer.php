<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends an account status notification email to a farmer or buyer
 * using Gmail SMTP via PHPMailer.
 */
function sendStatusEmail($toEmail, $toName, $role, $status) {

    // ---------- GMAIL SMTP CREDENTIALS ----------
    $smtpEmail    = 'makinafrezer@gmail.com';       // <-- CHANGE THIS
    $smtpAppPass  = 'acxtozmykrrxzrla';           // <-- CHANGE THIS (16-char App Password, no spaces)

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpEmail;
        $mail->Password   = $smtpAppPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender / recipient
        $mail->setFrom($smtpEmail, 'AgriMatch Admin');
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = "AgriMatch Account " . ucfirst($status);

        if ($status === 'verified') {
            $mail->Body = "
                <h3>Hello {$toName},</h3>
                <p>Congratulations! Your <strong>{$role}</strong> account on <strong>AgriMatch</strong> has been verified.</p>
                <p>You can now log in and start using the platform.</p>
                <p><a href='http://localhost/AgriMatch/auth/login.php'>Click here to log in</a></p>
                <br>
                <p>Regards,<br>AgriMatch Admin Team</p>
            ";
        } else {
            $mail->Body = "
                <h3>Hello {$toName},</h3>
                <p>We regret to inform you that your <strong>{$role}</strong> account registration on
                <strong>AgriMatch</strong> has been rejected after review of your submitted documents.</p>
                <p>If you believe this is a mistake, please contact the administrator or register again
                with correct documentation.</p>
                <br>
                <p>Regards,<br>AgriMatch Admin Team</p>
            ";
        }

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log the error so you can debug during the demo if something's wrong
        error_log("Email failed to send to {$toEmail}: {$mail->ErrorInfo}");
        return false;
    }
}