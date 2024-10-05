<?php
require_once('vendor/autoload.php'); // Inclure TCPDF via autoload

// Optimisation des paramètres d'exécution
ini_set('memory_limit', '12G');
ini_set('display_errors', 0);  // Désactiver l'affichage des erreurs pour éviter les sorties non voulues
date_default_timezone_set('Europe/Paris');
ob_start();  // Démarrer la mise en mémoire tampon de sortie

// --- Étape 1 : Lire et organiser les SMS par jour (deux fichiers XML) ---
$numeroRecherche = ['0660839365', '+33660839365','0622913131','+33622913131'];  // Numéros à rechercher
$smsByDay = [];  // Tableau regroupant les SMS par jour

// Fonction pour lire un fichier XML et ajouter les SMS dans le tableau $smsByDay
function lireFichierXML($fichier, &$smsByDay, $numeroRecherche = null) {
    if ($numeroRecherche === null) {
        $numeroRecherche = [];  // Pas de filtre si aucun numéro n'est spécifié
    }

    $reader = new XMLReader();
    $reader->open($fichier);

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'sms') {
            $address = $reader->getAttribute('address');
            if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                ajouterSmsAuTableau($smsByDay, $reader);
            }
        } elseif ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'mms') {
            $address = $reader->getAttribute('address');
            if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                ajouterMmsAuTableau($smsByDay, $reader);
            }
        } elseif ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'call') {
            $address = $reader->getAttribute('number');
            if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                ajouterCallAuTableau($smsByDay, $reader);
            }
        } elseif ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'mail') {
            $address = $reader->getAttribute('number');
            if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                ajouterMailAuTableau($smsByDay, $reader);
            }
        }

        gc_collect_cycles();  // Collecter les cycles de mémoire pour éviter les fuites
    }

    $reader->close();
}

// Fonction pour ajouter un SMS dans le tableau $smsByDay
function ajouterSmsAuTableau(&$smsByDay, $reader) {
    $date = $reader->getAttribute('date');
    $body = $reader->getAttribute('body');
    $contactName = $reader->getAttribute('contact_name');
    $nTelephone = $reader->getAttribute('address');

    $timestamp = (int)($date / 1000);  // Conversion du timestamp
    $dayKey = date('Y-m-d', $timestamp);  // Regrouper les messages par jour

    $smsByDay[$dayKey][] = [
        'title' => '<strong>SMS</strong> : ',
        'time' => date('H:i:s', $timestamp),  // Heure du SMS
        'message' => htmlspecialchars($body),
        'ntelephone' =>$nTelephone,
        'contact' => htmlspecialchars($contactName),
        'duration' => ''
    ];
}

function ajouterMmsAutableau(&$smsByDay, $reader) {
    $date = $reader->getAttribute('date');
    $contactName = $reader->getAttribute('contact_name');
    $nTelephone = $reader->getAttribute('address');

    $timestamp = (int)($date / 1000);  // Conversion du timestamp
    $dayKey = date('Y-m-d', $timestamp);  // Regrouper les messages par jour

    $smsByDay[$dayKey][] = [
        'title' => '<strong>MMS</strong> : ',
        'time' => date('H:i:s', $timestamp),  // Heure du MMS
        'message' => '<i>image non enregistré</i>',
        'ntelephone' =>$nTelephone,
        'contact' => htmlspecialchars($contactName),
        'duration' => ''
    ];
}

function ajouterCallAutableau(&$smsByDay, $reader) {
    $date = $reader->getAttribute('date');
    $contactName = $reader->getAttribute('contact_name');
    $duration = $reader->getAttribute('duration');
    $nTelephone = $reader->getAttribute('number');
    $durationFormatted = ($duration == 0) ? 'Appel manqué' : round($duration / 60, 2) . ' minutes';

    $timestamp = (int)($date / 1000);  // Conversion du timestamp
    $dayKey = date('Y-m-d', $timestamp);  // Regrouper les messages par jour

    $smsByDay[$dayKey][] = [
        'title' => '<strong>Appel Téléphonique</strong> : ',
        'time' => date('H:i:s', $timestamp),  // Heure appel
        'message' => '<i>appel non enregistré</i>',
        'ntelephone' =>$nTelephone,
        'contact' => htmlspecialchars($contactName),
        'duration' => '<strong>Durée :</strong>' . $durationFormatted
    ];
}

function ajouterMailAuTableau(&$smsByDay, $reader) {

    $timestamp = $reader->getAttribute('date');

 // Convertir les millisecondes en secondes
$timestamp = (float)$timestamp / 1000;

// $date = new \DateTime();
// Formatage en jour clé (Y-m-d)
$dayKey = date('Y-m-d',$timestamp);

    $body = $reader->getAttribute('body');
    $subject = $reader->getAttribute('subject');

    
    $smsByDay[$dayKey][] = [
        'title' => '<strong>Mail</strong> Sujet :'. $subject.' ',
        'time' => date('H:i:s', $timestamp),  // Heure du SMS
        'message' => htmlspecialchars($body),
        'ntelephone' =>'<i>aucun numéro de téléphone</i>',
        'contact' => '',
        'duration' => ''
    ];
}

// Lecture des fichiers XML
lireFichierXML('smsPerso.xml', $smsByDay, $numeroRecherche);  // Premier fichier avec filtrage par numéro
lireFichierXML('sms-20240818155416.xml', $smsByDay);  // Deuxième fichier, sans filtrage
lireFichierXML('calls-20240818155416.xml', $smsByDay);  // Troisième fichier sans filtrage
lireFichierXML('smses_backup.xml', $smsByDay);  // Quatrième fichier sans filtrage
lireFichierXML('email.xml', $smsByDay);  // Quatrième fichier sans filtrage


$dates = array_keys($smsByDay);
sort($dates);

// var_dump($dates);
// die;


// --- Étape 2 : Générer le PDF avec TCPDF ---
$pdf = new TCPDF();
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Anaïs Leblanc');
$pdf->SetTitle('Calendrier travail Solano');
$pdf->SetSubject('Calendrier travail');

// Définir les marges et les sauts de page automatiques
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);

// Ajouter une page au PDF
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// Trier les dates et calculer la période
$dates = array_keys($smsByDay);
sort($dates);

$firstDate = new DateTime($dates[0]);
$lastDate = new DateTime(end($dates));

$interval = DateInterval::createFromDateString('1 day');
$period = new DatePeriod($firstDate, $interval, $lastDate->modify('+1 day'));

// Titre principal du PDF
$pdf->SetFont('helvetica', 'B', 14);
//a Voir
$pdf->Cell(0, 10, "Calendrier des SMS: " . $firstDate->format('d M Y') . " - " . $lastDate->format('d M Y'), 0, 1, 'C');
$pdf->Ln(5);

// --- Étape 3 : Créer le tableau du calendrier ---
$pdf->SetFont('helvetica', 'B', 10);

// Afficher les jours de la semaine en en-tête
$joursSemaine = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
foreach ($joursSemaine as $jour) {
    $pdf->Cell(0, 10, $jour, 1, 1, 'C');  // Utiliser toute la largeur pour chaque jour
}

$pdf->SetFont('helvetica', '', 10);

// Fonction pour formater les dates en français
function formaterDateEnFrancais(DateTime $date) {
    $joursSemaine = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    $jourSemaine = $joursSemaine[(int)$date->format('w')];  // Jour de la semaine
    $jourMois = $date->format('d');  // Jour du mois
    $moisTexte = $mois[(int)$date->format('n') - 1];  // Mois en texte
    $annee = $date->format('Y');  // Année

    return "$jourSemaine $jourMois $moisTexte $annee";
}

// Fonction pour formater l'heure sous forme "05H17min34sec"
function formaterHeureEnFrancais($heure) {
    list($heures, $minutes, $secondes) = explode(':', $heure);
    return "reçu à : {$heures}H{$minutes}min{$secondes}sec ";
}

foreach ($period as $day) {
    $currentDate = $day->format('Y-m-d');
    $formattedDate = formaterDateEnFrancais($day);

    // On n'affiche que les jours où il y a des informations (SMS, MMS, Appels)
    if (isset($smsByDay[$currentDate])) {
        $content = "";
        foreach ($smsByDay[$currentDate] as $sms) {
            $formattedTime = formaterHeureEnFrancais($sms['time']);
            $content .= '<p>'.$sms['title'].$formattedTime . " <strong>N°Téléphone</strong> : ". $sms['ntelephone'] ." <strong>Nom Contact</strong> : " . $sms['contact'] . ": " . $sms['message'] ." ".$sms['duration']."</p>";
       // Supprimer le message après l'avoir utilisé
       unset($smsByDay[$currentDate][$key]);
        }

        $pdf->writeHTMLCell(0, 50, '', '', "<p>$formattedDate</p><p>$content</p>", 1, 1, 0, true, 'L', true);
    }
}

// --- Étape 4 : Exporter le PDF dans un dossier ---
ob_end_clean();  // Nettoyer le tampon de sortie

$cheminPdf = __DIR__ . '/dossiers_pdfs/calendrier_sms.pdf';  // Chemin du fichier PDF dans un sous-dossier "dossiers_pdfs"
$pdf->Output($cheminPdf, 'F');  // F = enregistrer sur le serveur


exit;

?>