<?php
require_once('vendor/autoload.php'); // Inclure TCPDF via autoload

// Optimisation des paramètres d'exécution
ini_set('memory_limit', '12G');
ini_set('display_errors', 0);  // Désactiver l'affichage des erreurs pour éviter les sorties non voulues
// setlocale(LC_TIME, 'fr_FR.UTF-8'); ne tenait pas compte du fuseau horaire sur windows
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
            // Si des numéros sont fournis, on filtre par ces numéros, sinon on inclut tous les SMS
            if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                ajouterSmsAuTableau($smsByDay, $reader);
            }
        }elseif ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'mms') {
            $address = $reader->getAttribute('address');
            // Si des numéros sont fournis, on filtre par ces numéros, sinon on inclut tous les SMS
            if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                ajouterMmsAuTableau($smsByDay, $reader);
            }
        }elseif ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'call') {
            $address = $reader->getAttribute('number');
            // Si des numéros sont fournis, on filtre par ces numéros, sinon on inclut tous les SMS
            if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                ajouterCallAuTableau($smsByDay, $reader);
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

    $timestamp = (int)($date / 1000);  // Conversion du timestamp
    $dayKey = date('Y-m-d', $timestamp);  // Regrouper les messages par jour

    // Ajouter le SMS dans le tableau regroupé par jour
    $smsByDay[$dayKey][] = [
        'title' => '<strong>SMS</strong> : ',
        'time' => date('H:i:s', $timestamp),  // Heure du SMS
        'message' => htmlspecialchars($body),
        'contact' => htmlspecialchars($contactName),
        'duration' => ''
    ];
}

function ajouterMmsAutableau(&$smsByDay, $reader)
{
    $date = $reader->getAttribute('date');
    // $body = $reader->getAttribute('body');
    $contactName = $reader->getAttribute('contact_name');

    $timestamp = (int)($date / 1000);  // Conversion du timestamp
    $dayKey = date('Y-m-d', $timestamp);  // Regrouper les messages par jour

    // Ajouter le SMS dans le tableau regroupé par jour
    $smsByDay[$dayKey][] = [
        'title' => '<strong>MMS</strong> : ',
        'time' => date('H:i:s', $timestamp),  // Heure du MMS
        'message' => '<i>image non enregistré</i>',
        'contact' => htmlspecialchars($contactName),
        'duration' => ''
    ];
}

function ajouterCallAutableau(&$smsByDay, $reader)
{
    $date = $reader->getAttribute('date');
    // $body = $reader->getAttribute('body');
    $contactName = $reader->getAttribute('contact_name');

    $duration = $reader->getAttribute('duration');

    $durationFormatted = ($duration == 0) ? 'Appel manqué' : round($duration / 60, 2)  . ' minutes';

    $timestamp = (int)($date / 1000);  // Conversion du timestamp
    $dayKey = date('Y-m-d', $timestamp);  // Regrouper les messages par jour

    // Ajouter le SMS dans le tableau regroupé par jour
    $smsByDay[$dayKey][] = [
        'title' => '<strong>Appel Téléphonique</strong> : ',
        'time' => date('H:i:s', $timestamp),  // Heure appel
        'message' => '<i>appel non enregistré</i>',
        'contact' => htmlspecialchars($contactName),
        'duration' => '<strong>Durée :</strong>'.$durationFormatted
    ];
}

// Lecture des fichiers XML
lireFichierXML('smsPerso.xml', $smsByDay, $numeroRecherche);  // Premier fichier avec filtrage par numéro
lireFichierXML('sms-20240818155416.xml', $smsByDay);  // Deuxième fichier, sans filtrage
lireFichierXML('calls-20240818155416.xml', $smsByDay);  // Troisiéme fichier sans filtrage

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

    // Format de la date souhaité : exemple "lundi 13 mars 2024"
    $formattedDate = formaterDateEnFrancais($day);

    if (isset($smsByDay[$currentDate])) {
        $content = "";
        foreach ($smsByDay[$currentDate] as $sms) {
            // Utiliser la fonction pour formater l'heure avec balises <strong>
            $formattedTime = formaterHeureEnFrancais($sms['time']);
            $content .= '<p>'.$sms['title'].$formattedTime . " <strong>Contact</strong> : " . $sms['contact'] . ": " . $sms['message'] ." ".$sms['duration']."</p>";
        }

        // Utilisation de writeHTMLCell pour supporter le HTML
        $pdf->writeHTMLCell(0, 50, '', '', "<p>$formattedDate</p><p>$content</p>", 1, 1, 0, true, 'L', true);
    } else {
        // Cellule vide avec seulement la date formatée
        $pdf->Cell(0, 50, $formattedDate, 1, 1, 'C');
    }
}

// --- Étape 4 : Exporter le PDF ---
ob_end_clean();  // Nettoyer le tampon de sortie
$pdf->Output('calendrier_sms.pdf', 'I');  // I = afficher dans le navigateur, D = télécharger
?>
