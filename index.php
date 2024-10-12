<?php
require_once('vendor/autoload.php'); // Inclure TCPDF via autoload

// Optimisation des paramètres d'exécution
ini_set('memory_limit', '12G');
ini_set('display_errors', 0);  // Désactiver l'affichage des erreurs pour éviter les sorties non voulues
date_default_timezone_set('Europe/Paris');
ob_start();  // Démarrer la mise en mémoire tampon de sortie

// --- Étape 1 : Lire et organiser les SMS par jour (fichiers XML) ---
$numeroRecherche = ['0660839365', '+33660839365','0622913131','+33622913131'];  // Numéros à rechercher
$smsByDay = [];  // Tableau regroupant les SMS par jour

// Fonction pour lire un fichier XML et ajouter les SMS dans le tableau $smsByDay
function lireFichierXML($fichier, &$smsByDay, $numeroRecherche = null) {
    if ($numeroRecherche === null) {
        $numeroRecherche = [];  // Pas de filtre si aucun numéro n'est spécifié
    }

    $reader = new XMLReader();
    if (!$reader->open($fichier)) {
        // Gérer l'erreur si le fichier ne peut pas être ouvert
        return;
    }

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT) {
            switch ($reader->name) {
                case 'sms':
                    $address = $reader->getAttribute('address');
                    if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                        ajouterSmsAuTableau($smsByDay, $reader);
                    }
                    break;
                case 'mms':
                    $address = $reader->getAttribute('address');
                    if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                        ajouterMmsAuTableau($smsByDay, $reader);
                    }
                    break;
                case 'call':
                    $address = $reader->getAttribute('number');
                    if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                        ajouterCallAuTableau($smsByDay, $reader);
                    }
                    break;
                case 'mail':
                    $address = $reader->getAttribute('number');
                    if (empty($numeroRecherche) || in_array($address, $numeroRecherche)) {
                        ajouterMailAuTableau($smsByDay, $reader);
                    }
                    break;
            }
        }

        gc_collect_cycles();  // Collecter les cycles de mémoire pour éviter les fuites
    }

    $reader->close();
}

// Fonctions pour ajouter des éléments au tableau $smsByDay
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
        'ntelephone' => $nTelephone,
        'contact' => htmlspecialchars($contactName),
        'duration' => ''
    ];
}

function ajouterMmsAuTableau(&$smsByDay, $reader) {
    $date = $reader->getAttribute('date');
    $contactName = $reader->getAttribute('contact_name');
    $nTelephone = $reader->getAttribute('address');

    $timestamp = (int)($date / 1000);  // Conversion du timestamp
    $dayKey = date('Y-m-d', $timestamp);  // Regrouper les messages par jour

    $smsByDay[$dayKey][] = [
        'title' => '<strong>MMS</strong> : ',
        'time' => date('H:i:s', $timestamp),  // Heure du MMS
        'message' => '<i>image non enregistré</i>',
        'ntelephone' => $nTelephone,
        'contact' => htmlspecialchars($contactName),
        'duration' => ''
    ];
}

function ajouterCallAuTableau(&$smsByDay, $reader) {
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
        'ntelephone' => $nTelephone,
        'contact' => htmlspecialchars($contactName),
        'duration' => '<strong>Durée :</strong> ' . $durationFormatted
    ];
}

function ajouterMailAuTableau(&$smsByDay, $reader) {
    $timestamp = $reader->getAttribute('date');

    // Convertir les millisecondes en secondes
    $timestamp = (float)$timestamp / 1000;

    // Formatage en jour clé (Y-m-d)
    $dayKey = date('Y-m-d', $timestamp);

    $body = $reader->getAttribute('body');
    $subject = $reader->getAttribute('subject');

    $smsByDay[$dayKey][] = [
        'title' => '<strong>Mail</strong> Sujet : ' . htmlspecialchars($subject) . ' ',
        'time' => date('H:i:s', $timestamp),  // Heure du Mail
        'message' => htmlspecialchars($body),
        'ntelephone' => '<i>aucun numéro de téléphone</i>',
        'contact' => '',
        'duration' => ''
    ];
}

// Lecture des fichiers XML
lireFichierXML('smsPerso.xml', $smsByDay, $numeroRecherche);  // Premier fichier avec filtrage par numéro
lireFichierXML('sms-20240818155416.xml', $smsByDay);  // Deuxième fichier, sans filtrage
lireFichierXML('calls-20240818155416.xml', $smsByDay);  // Troisième fichier sans filtrage
lireFichierXML('smses_backup.xml', $smsByDay);  // Quatrième fichier sans filtrage
lireFichierXML('email.xml', $smsByDay);  // Cinquième fichier sans filtrage

$dates = array_keys($smsByDay);
sort($dates);

// --- Étape 2 : Générer le Premier PDF avec les SMS ---
$pdf1 = new TCPDF();
$pdf1->SetCreator(PDF_CREATOR);
$pdf1->SetAuthor('Anaïs Leblanc');
$pdf1->SetTitle('Calendrier travail Solano');
$pdf1->SetSubject('Calendrier travail');

// Définir les marges et les sauts de page automatiques
$pdf1->SetMargins(10, 10, 10);
$pdf1->SetAutoPageBreak(TRUE, 10);

// Ajouter une page au PDF
$pdf1->AddPage();
$pdf1->SetFont('helvetica', '', 10);

// Trier les dates et calculer la période
$dates = array_keys($smsByDay);
sort($dates);

if (count($dates) > 0) {
    $firstDate = new DateTime($dates[0]);
    $lastDate = new DateTime(end($dates));

    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($firstDate, $interval, $lastDate->modify('+1 day'));

    // Titre principal du PDF
    $pdf1->SetFont('helvetica', 'B', 14);
    $pdf1->Cell(0, 10, "Calendrier des SMS: " . $firstDate->format('d M Y') . " - " . $lastDate->format('d M Y'), 0, 1, 'C');
    $pdf1->Ln(5);

    // --- Étape 3 : Créer le Tableau du Calendrier pour le Premier PDF ---
    $pdf1->SetFont('helvetica', 'B', 10);

    // Afficher les jours de la semaine en en-tête
    $joursSemaine = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    foreach ($joursSemaine as $jour) {
        $pdf1->Cell(0, 10, $jour, 1, 1, 'C');  // Utiliser toute la largeur pour chaque jour
    }

    $pdf1->SetFont('helvetica', '', 10);

    // Fonction pour formater les dates en français
    function formaterDateEnFrancais(DateTime $date) {
        $joursSemaine = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        $jourSemaine = $joursSemaine[(int)$date->format('w')];  // Jour de la semaine
        $jourMois = $date->format('d');  // Jour du mois
        $moisTexte = $mois[(int)$date->format('n') - 1];  // Mois en texte
        $annee = $date->format('Y');  // Année

        return ucfirst("$jourSemaine $jourMois $moisTexte $annee");
    }

    // Fonction pour formater l'heure sous forme "05H17min34sec"
    function formaterHeureEnFrancais($heure) {
        list($heures, $minutes, $secondes) = explode(':', $heure);
        return "Reçu à : {$heures}H{$minutes}min{$secondes}sec ";
    }

    foreach ($period as $day) {
        $currentDate = $day->format('Y-m-d');
        $formattedDate = formaterDateEnFrancais($day);

        // On n'affiche que les jours où il y a des informations (SMS, MMS, Appels, Mails)
        if (isset($smsByDay[$currentDate])) {
            $content = "";
            foreach ($smsByDay[$currentDate] as $sms) {
                $formattedTime = formaterHeureEnFrancais($sms['time']);
                $content .= '<p>' . $sms['title'] . $formattedTime . "<strong>N° Téléphone</strong> : " . $sms['ntelephone'] . " <strong>Nom Contact</strong> : " . $sms['contact'] . ": " . $sms['message'] . " " . $sms['duration'] . "</p>";
            }

            $pdf1->writeHTMLCell(0, 50, '', '', "<p><strong>$formattedDate</strong></p><p>$content</p>", 1, 1, 0, true, 'L', true);
        }
    }
}

// --- Étape 4 : Calculer l'Amplitude de Travail par Jour ---
$amplitudeByDay = [];

foreach ($smsByDay as $day => $messages) {
    if (count($messages) > 0) {
        // Extraire les timestamps des messages
        $timestamps = array_map(function($msg) use ($day) {
            return strtotime($day . ' ' . $msg['time']);
        }, $messages);

        // Trouver le premier et le dernier timestamp
        $firstTimestamp = min($timestamps);
        $lastTimestamp = max($timestamps);

        // Calculer la différence en secondes
        $differenceSeconds = $lastTimestamp - $firstTimestamp;

        // Convertir en heures, minutes, secondes
        $heures = floor($differenceSeconds / 3600);
        $minutes = floor(($differenceSeconds % 3600) / 60);
        $secondes = $differenceSeconds % 60;

        // Formater l'amplitude
        $amplitudeFormatted = "{$heures}h {$minutes}min {$secondes}sec";

        // Stocker l'amplitude
        $amplitudeByDay[$day] = $amplitudeFormatted;
    }
}

// --- Étape 5 : Générer le Second PDF avec les Amplitudes ---
$pdf2 = new TCPDF();
$pdf2->SetCreator(PDF_CREATOR);
$pdf2->SetAuthor('Anaïs Leblanc');
$pdf2->SetTitle('Amplitude de Travail');
$pdf2->SetSubject('Amplitude de Travail');

// Définir les marges et les sauts de page automatiques
$pdf2->SetMargins(10, 10, 10);
$pdf2->SetAutoPageBreak(TRUE, 10);

// Ajouter une page au PDF
$pdf2->AddPage();
$pdf2->SetFont('helvetica', '', 10);

// Titre principal du PDF
if (count($dates) > 0) {
    $pdf2->SetFont('helvetica', 'B', 14);
    $pdf2->Cell(0, 10, "Amplitude de Travail: " . $firstDate->format('d M Y') . " - " . $lastDate->format('d M Y'), 0, 1, 'C');
    $pdf2->Ln(5);
}

// Définir la police pour le contenu
$pdf2->SetFont('helvetica', '', 10);

// Créer un tableau pour les amplitudes
$html = '<table border="1" cellpadding="4">
            <thead>
                <tr>
                    <th><strong>Date</strong></th>
                    <th><strong>Amplitude de Travail</strong></th>
                </tr>
            </thead>
            <tbody>';

foreach ($amplitudeByDay as $day => $amplitude) {
    $dateObj = new DateTime($day);
    $formattedDate = formaterDateEnFrancais($dateObj);
    $html .= "<tr>
                <td>{$formattedDate}</td>
                <td>{$amplitude}</td>
              </tr>";
}

$html .= '</tbody></table>';

// Écrire le contenu HTML dans le PDF
$pdf2->writeHTML($html, true, false, true, false, '');

// --- Étape 6 : Exporter les Deux PDFs dans un Dossier ---
ob_end_clean();  // Nettoyer le tampon de sortie

// Définir le chemin du dossier de sortie
$dossierPdfs = __DIR__ . '/dossiers_pdfs';
if (!is_dir($dossierPdfs)) {
    mkdir($dossierPdfs, 0777, true);  // Créer le dossier s'il n'existe pas
}

// Chemin des fichiers PDF
$cheminPdf1 = $dossierPdfs . '/calendrier_sms.pdf';  // Premier PDF
$cheminPdf2 = $dossierPdfs . '/amplitude_travail.pdf';  // Second PDF

// Sauvegarder les PDFs
$pdf1->Output($cheminPdf1, 'F');  // Enregistrer sur le serveur
$pdf2->Output($cheminPdf2, 'F');  // Enregistrer sur le serveur

exit;
?>
