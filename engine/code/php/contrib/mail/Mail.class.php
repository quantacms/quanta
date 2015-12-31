<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 16/05/15
 * Time: 16:45
 */

class Mail extends Node {

  /**
   * Send an email using phpmailer.
   * @throws phpmailerException
   */
  public function send() {
    require_once('mailer/PHPMailerAutoload.php');
    // $mail->SMTPDebug  = 2;
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = 'tls';
    $mail->Username = 'aldo.tripiciano@gmail.com';
    $mail->Password = 'blacklore3X';
    $mail->Port = 587;
    $mail->setFrom('aldo.tripiciano@gmail.com', 'Mailer');
    // $mail->addAddress($this->getData('to'), $this->getData('to'));
    $mail->addAddress('info@pucarasicurezza.it', 'info@pucarasicurezza.it');
    $mail->addAddress('aldo.tripiciano@gmail.com', 'aldo.tripiciano@gmail.com');
    $mail->isHTML(true);
    // $mail->Subject = $this->getTitle();
    $mail->Subject = 'Nuova richiesta dal sito';
    $mail->Body    = 'Hai ricevuto questa richiesta dal sito:<br/><br/>' . $this->getContent();
    $mail->AltBody = $this->getContent();
    if(!$mail->send()) {
      new Message($this->env, 'Mailer Error: ' . $mail->ErrorInfo, MESSAGE_ERROR);
    } else {
      $this->delete();
    }
  }
} 