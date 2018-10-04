<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 16/05/15
 * Time: 16:45
 */

class Mail extends Node {
  /**
   * Send an email using phpmailer.
   */
  public function send() {
    // TODO: use composer autoload?
		$phpmailer = $this->env->dir['vendor'] . '/phpmailer/phpmailer/src/PHPMailer.php';
    $smtp = $this->env->dir['vendor'] . '/phpmailer/phpmailer/src/SMTP.php';
    $exception = $this->env->dir['vendor'] . '/phpmailer/phpmailer/src/Exception.php';
		
		require_once($exception);
		require_once($phpmailer);
		require_once($smtp);
    // SMTPDebug information (for testing).
    // 1 = errors and messages.
    // 2 = messages only.
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $this->getData('host');
    $mail->SMTPAuth = !empty($this->getData('SMTPAuth')) ? $this->getData('SMTPAuth') : true;
    $mail->SMTPSecure = !empty($this->getData('SMTPSecure')) ? $this->getData('SMTPSecure') : 'tls';
    $mail->SMTPDebug = !empty($this->getData('SMTPDebug')) ? $this->getData('SMTPDebug') : 0;
		$mail->Username = $this->getData('username');
    $mail->Password = $this->getData('password');
    $mail->Port = $this->getData('port');
    $mail->setFrom($this->getData('from'), $this->getData('from_name'));
    $mail->addAddress($this->getData('to'), $this->getData('to_name'));
    $mail->isHTML(true);
    // $mail->Subject = $this->getTitle();
    $mail->Subject = $this->getData('subject');
    $mail->Body    = $this->getBody();
    $mail->AltBody = $this->getBody();
    if (!empty($this->getData('reply_to'))){
      $mail->AddReplyTo($this->getData('reply_to'), '');    
    }
    try {
      // Send the email.
      $mail->send();
		  $this->delete();
		}
		catch(Exception $ex) {
      // Catch any errors.
      new Message($this->env, 'Mailer Error: ' . $mail->ErrorInfo, MESSAGE_ERROR);
		}
  }
} 
