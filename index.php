<?php
require_once('vendor/autoload.php'); // Inclure TCPDF via autoload

// Optimisation des paramètres d'exécution
ini_set('memory_limit', '24G');
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
lireFichierXML('smsPerso.xml', $smsByDay, $numeroRecherche);  // j'ai juste recherché avec les numéros intéressant
lireFichierXML('sms-20240818155416.xml', $smsByDay);  // telephone pro actuel je traite les sms et mms
lireFichierXML('calls-20240818155416.xml', $smsByDay);  // telephone pro actuel je traite les appels
lireFichierXML('smses_backup.xml', $smsByDay);  // vieux téléphone pro j'ai regroupé tout les fichiers en .csv
lireFichierXML('email.xml', $smsByDay);  // email

$dates = array_keys($smsByDay);
sort($dates);

// Vérifier si des dates ont été trouvées
if (count($dates) === 0) {
    // Aucun SMS, MMS, appel ou mail trouvé
    // Vous pouvez ajouter une logique pour gérer ce cas si nécessaire
    exit('Aucune donnée trouvée.');
}

// --- Étape 2 : Générer le Premier PDF avec les SMS ---
$pdfSms = new TCPDF();  // Renommé pour éviter le conflit
$pdfSms->SetCreator(PDF_CREATOR);
$pdfSms->SetAuthor('Anaïs Leblanc');
$pdfSms->SetTitle('Calendrier travail Solano');
$pdfSms->SetSubject('Calendrier travail');

// Définir les marges et les sauts de page automatiques
$pdfSms->SetMargins(10, 10, 10);
$pdfSms->SetAutoPageBreak(TRUE, 10);

// Ajouter une page au PDF
$pdfSms->AddPage();
$pdfSms->SetFont('helvetica', '', 10);

// Trier les dates et calculer la période
sort($dates);

$firstDate = new DateTime($dates[0]);
$lastDate = new DateTime(end($dates));

$interval = DateInterval::createFromDateString('1 day');
$period = new DatePeriod($firstDate, $interval, $lastDate->modify('+1 day'));

// Titre principal du PDF
$pdfSms->SetFont('helvetica', 'B', 14);
$pdfSms->Cell(0, 10, "Calendrier des SMS: " . $firstDate->format('d M Y') . " - " . $lastDate->format('d M Y'), 0, 1, 'C');
$pdfSms->Ln(5);

// --- Étape 3 : Créer le Tableau du Calendrier pour le Premier PDF ---
$pdfSms->SetFont('helvetica', 'B', 10);

// Afficher les jours de la semaine en en-tête
$joursSemaine = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
foreach ($joursSemaine as $jour) {
    $pdfSms->Cell(0, 10, $jour, 1, 1, 'C');  // Utiliser toute la largeur pour chaque jour
}

$pdfSms->SetFont('helvetica', '', 10);

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

        $pdfSms->writeHTMLCell(0, 50, '', '', "<p><strong>$formattedDate</strong></p><p>$content</p>", 1, 1, 0, true, 'L', true);
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
$pdfAmplitude = new TCPDF();  // Renommé pour éviter le conflit
$pdfAmplitude->SetCreator(PDF_CREATOR);
$pdfAmplitude->SetAuthor('Anaïs Leblanc');
$pdfAmplitude->SetTitle('Amplitude de Travail');
$pdfAmplitude->SetSubject('Amplitude de Travail');

// Définir les marges et les sauts de page automatiques
$pdfAmplitude->SetMargins(10, 10, 10);
$pdfAmplitude->SetAutoPageBreak(TRUE, 10);

// Ajouter une page au PDF
$pdfAmplitude->AddPage();
$pdfAmplitude->SetFont('helvetica', '', 10);

// Titre principal du PDF
$pdfAmplitude->SetFont('helvetica', 'B', 14);
$pdfAmplitude->Cell(0, 10, "Amplitude de Travail: " . $firstDate->format('d M Y') . " - " . $lastDate->format('d M Y'), 0, 1, 'C');
$pdfAmplitude->Ln(5);

// Définir la police pour le contenu
$pdfAmplitude->SetFont('helvetica', '', 10);

// Créer un tableau pour les amplitudes
$htmlAmplitude = '<table border="1" cellpadding="4">
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
    $htmlAmplitude .= "<tr>
                    <td>{$formattedDate}</td>
                    <td>{$amplitude}</td>
                  </tr>";
}

$htmlAmplitude .= '</tbody></table>';

// Écrire le contenu HTML dans le PDF
$pdfAmplitude->writeHTML($htmlAmplitude, true, false, true, false, '');

// --- Étape 6 : Générer le Troisième PDF avec le Calendrier classique ---

// Définir les horaires de travail par jour
$workingHours = [
    'Monday' => [['08:00', '12:00'], ['14:00', '18:00']],
    'Tuesday' => [['09:00', '12:00'], ['14:00', '19:00']],
    'Wednesday' => [['08:30', '12:00'], ['14:00', '17:30']],
    'Thursday' => [['09:00', '12:00'], ['14:00', '19:00']],
    'Friday' => [['09:00', '12:00'], ['14:00', '19:00']],
    'Saturday' => [],
    'Sunday' => []
];

// Collecter les heures des messages par jour
$messageTimesByDay = [];
foreach ($smsByDay as $day => $messages) {
    foreach ($messages as $msg) {
        $time = $msg['time'];  // Format 'H:i:s'
        $hour = intval(substr($time, 0, 2));
        $messageTimesByDay[$day][] = $hour;
    }
}

// Initialiser le troisième PDF en format paysage
$pdfCalendar = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdfCalendar->SetCreator(PDF_CREATOR);
$pdfCalendar->SetAuthor('Anaïs Leblanc');
$pdfCalendar->SetTitle('Calendrier de Travail');
$pdfCalendar->SetSubject('Calendrier de Travail');

// Définir les marges et les sauts de page automatiques
$pdfCalendar->SetMargins(10, 10, 10);
$pdfCalendar->SetAutoPageBreak(TRUE, 10);

// Initialiser les tableaux pour stocker les totaux par année et par mois
$totalWorkingHoursByYear = [];
$totalExtraHoursByYear = [];

$totalWorkingHoursByMonth = [];
$totalExtraHoursByMonth = [];

// Calculer les semaines entre la première et la dernière date
if (count($dates) > 0) {
    $firstMonday = clone $firstDate;
    // Si le premier jour n'est pas un lundi, ajustez pour le lundi précédent
    if ($firstMonday->format('N') != 1) {
        $firstMonday->modify('last Monday');
    }

    $lastSunday = clone $lastDate;
    // Si le dernier jour n'est pas un dimanche, ajustez pour le prochain dimanche
    if ($lastSunday->format('N') != 7) {
        $lastSunday->modify('next Sunday');
    }

    $intervalWeek = new DateInterval('P1W');
    $periodWeek = new DatePeriod($firstMonday, $intervalWeek, $lastSunday->modify('+1 day'));

    foreach ($periodWeek as $weekStart) {
        // Ajouter une nouvelle page
        $pdfCalendar->AddPage();

        // Obtenir les jours de la semaine
        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $day = clone $weekStart;
            $day->modify("+$i day");
            $weekDays[] = $day;
        }

        // Initialiser les totaux pour la semaine
        $totalWorkingHoursWeek = 0;
        $totalExtraHoursWeek = 0;

        // Définir les propriétés de la table
        $cellWidthHour = 15; // Largeur pour les étiquettes d'heures
        $cellWidthDay = (280 - $cellWidthHour) / 7; // Ajuster la largeur totale (A4 paysage ~297mm de large)
        $cellHeight = 6; // Hauteur par ligne horaire

        // Titre de la semaine
        $weekTitle = "Semaine du " . $weekStart->format('d M Y') . " au " . $weekDays[6]->format('d M Y');
        $pdfCalendar->SetFont('helvetica', 'B', 14);
        $pdfCalendar->Cell(0, 10, $weekTitle, 0, 1, 'C');
        $pdfCalendar->Ln(2);

        // Dessiner les en-têtes des jours
        $pdfCalendar->SetFont('helvetica', 'B', 10);
        // Première cellule vide pour les étiquettes d'heures
        $pdfCalendar->Cell($cellWidthHour, $cellHeight, '', 1, 0, 'C', 0);
        // En-têtes des jours
        $frenchDayNames = [
            'Monday' => 'Lundi',
            'Tuesday' => 'Mardi',
            'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi',
            'Friday' => 'Vendredi',
            'Saturday' => 'Samedi',
            'Sunday' => 'Dimanche'
        ];
        foreach ($weekDays as $day) {
            $dayNameEnglish = $day->format('l'); // 'Monday', etc.
            $dayNameFrench = $frenchDayNames[$dayNameEnglish];
            $dayDate = $day->format('d/m');
            $header = "$dayNameFrench\n$dayDate";
            $pdfCalendar->MultiCell($cellWidthDay, $cellHeight, $header, 1, 'C', 0, 0, '', '', true, 0, false, true, $cellHeight, 'M');
        }
        $pdfCalendar->Ln($cellHeight);

        // Calculer les heures de travail pour la semaine
        foreach ($weekDays as $day) {
            $currentDay = $day->format('Y-m-d');
            $dayNameEnglish = $day->format('l');

            // Obtenir l'année et le mois
            $year = $day->format('Y');
            $month = $day->format('Y-m');

            // Initialiser les totaux pour l'année et le mois si nécessaire
            if (!isset($totalWorkingHoursByYear[$year])) {
                $totalWorkingHoursByYear[$year] = 0;
                $totalExtraHoursByYear[$year] = 0;
            }
            if (!isset($totalWorkingHoursByMonth[$month])) {
                $totalWorkingHoursByMonth[$month] = 0;
                $totalExtraHoursByMonth[$month] = 0;
            }

            // Initialiser les horaires de travail pour ce jour
            $currentWorkingHours = [];
            if ($currentDay === '2021-12-31') {
                // Exception pour le 31/12/2021 : Horaires jusqu'à 12h
                $currentWorkingHours = [['08:00', '12:00']];
            } else {
                // Utiliser les horaires de travail standards
                if (isset($workingHours[$dayNameEnglish])) {
                    $currentWorkingHours = $workingHours[$dayNameEnglish];
                }
            }

            // Calculer les heures de travail pour le jour
            $workingHoursDay = 0;
            foreach ($currentWorkingHours as $interval) {
                list($start, $end) = $interval;
                $startTime = DateTime::createFromFormat('H:i', $start);
                $endTime = DateTime::createFromFormat('H:i', $end);
                $diff = $endTime->diff($startTime);
                $hours = $diff->h + ($diff->i / 60);
                $workingHoursDay += $hours;
            }
            $totalWorkingHoursWeek += $workingHoursDay;

            // Ajouter les heures de travail du jour aux totaux annuels et mensuels
            $totalWorkingHoursByYear[$year] += $workingHoursDay;
            $totalWorkingHoursByMonth[$month] += $workingHoursDay;
        }

        // Dessiner les lignes horaires
        $pdfCalendar->SetFont('helvetica', '', 8);
        for ($hour = 0; $hour < 24; $hour++) {
            // Première cellule avec l'étiquette de l'heure
            $hourLabel = sprintf('%02d:00', $hour);
            $pdfCalendar->Cell($cellWidthHour, $cellHeight, $hourLabel, 1, 0, 'C', 0);

            // Itérer à travers chaque jour
            foreach ($weekDays as $day) {
                $currentDay = $day->format('Y-m-d');
                $dayNameEnglish = $day->format('l');

                // Obtenir l'année et le mois
                $year = $day->format('Y');
                $month = $day->format('Y-m');

                // Initialiser les horaires de travail pour ce jour
                $currentWorkingHours = [];
                if ($currentDay === '2021-12-31') {
                    $currentWorkingHours = [['08:00', '12:00']];
                } else {
                    if (isset($workingHours[$dayNameEnglish])) {
                        $currentWorkingHours = $workingHours[$dayNameEnglish];
                    }
                }

                // Déterminer si l'heure actuelle est dans les horaires de travail
                $isWorkingHour = false;
                foreach ($currentWorkingHours as $intervals) {
                    list($start, $end) = $intervals;
                    $startHour = intval(substr($start, 0, 2));
                    $endHour = intval(substr($end, 0, 2));
                    $startMinute = intval(substr($start, 3, 2));
                    $endMinute = intval(substr($end, 3, 2));

                    // Vérifier si l'heure actuelle est dans l'intervalle de travail
                    if ($hour >= $startHour && $hour < $endHour) {
                        $isWorkingHour = true;
                        break;
                    } elseif ($hour == $endHour && $endMinute > 0) {
                        $isWorkingHour = true;
                        break;
                    }
                }

                // Déterminer s'il y a des messages à cette heure
                $hasMessage = isset($messageTimesByDay[$currentDay]) && in_array($hour, $messageTimesByDay[$currentDay]);

                // Définir la couleur de la cellule
                if ($isWorkingHour) {
                    $pdfCalendar->SetFillColor(0, 255, 0); // Vert
                    $fill = 1;
                } elseif ($hasMessage) {
                    $pdfCalendar->SetFillColor(128, 0, 128); // Violet
                    $fill = 1;
                    // Incrémenter les heures supplémentaires
                    $totalExtraHoursWeek += 1;
                    // Ajouter aux totaux annuels et mensuels
                    $totalExtraHoursByYear[$year] += 1;
                    $totalExtraHoursByMonth[$month] += 1;
                } else {
                    $fill = 0;
                }

                // Dessiner la cellule
                $pdfCalendar->Cell($cellWidthDay, $cellHeight, '', 1, 0, 'C', $fill);
            }
            $pdfCalendar->Ln($cellHeight);
        }

        // Ajouter une légende pour les couleurs
        $pdfCalendar->SetFillColor(211, 211, 211);
        $pdfCalendar->SetTextColor(0);
        $pdfCalendar->SetFont('helvetica', '', 8);
        $pdfCalendar->Cell(0, $cellHeight, 'Légende: Vert = Horaires de travail, Violet = Messages hors horaires', 0, 1, 'L', 1);

        // Écrire les totaux en bas de page sur la même ligne avec surlignage
        $pdfCalendar->Ln(5); // Espace avant les totaux
        $pdfCalendar->SetFont('helvetica', 'B', 12);

        // Construire le contenu HTML pour les totaux
        $htmlTotals = '<p>Total des heures de travail pour la semaine : <span style="color:green;">' . round($totalWorkingHoursWeek, 2) . ' heures</span> &nbsp;&nbsp;&nbsp; Total des heures supplémentaires pour la semaine : <span style="color:purple;">' . $totalExtraHoursWeek . ' heures</span></p>';

        // Écrire le contenu HTML dans le PDF
        $pdfCalendar->writeHTML($htmlTotals);
    }

    // --- Ajouter le résumé à la fin du PDF ---
    $pdfCalendar->AddPage();
    $pdfCalendar->SetFont('helvetica', 'B', 14);
    $pdfCalendar->Cell(0, 10, 'Résumé des heures travaillées', 0, 1, 'C');
    $pdfCalendar->Ln(5);

    // Afficher le tableau des totaux par année
    $pdfCalendar->SetFont('helvetica', 'B', 12);
    $pdfCalendar->Cell(0, 10, 'Totaux par année', 0, 1, 'L');

    $htmlYearly = '<table border="1" cellpadding="4">
    <thead>
    <tr>
        <th>Année</th>
        <th>Heures travaillées</th>
        <th>Heures supplémentaires</th>
    </tr>
    </thead>
    <tbody>';

    foreach ($totalWorkingHoursByYear as $year => $workingHours) {
        $extraHours = isset($totalExtraHoursByYear[$year]) ? $totalExtraHoursByYear[$year] : 0;
        $htmlYearly .= '<tr>
        <td>' . $year . '</td>
        <td>' . round($workingHours, 2) . '</td>
        <td>' . $extraHours . '</td>
        </tr>';
    }

    $htmlYearly .= '</tbody></table>';

    $pdfCalendar->writeHTML($htmlYearly);

    // Afficher le tableau des totaux par mois
    $pdfCalendar->Ln(10);
    $pdfCalendar->SetFont('helvetica', 'B', 12);
    $pdfCalendar->Cell(0, 10, 'Totaux par mois', 0, 1, 'L');

    $htmlMonthly = '<table border="1" cellpadding="4">
    <thead>
    <tr>
        <th>Mois</th>
        <th>Heures travaillées</th>
        <th>Heures supplémentaires</th>
    </tr>
    </thead>
    <tbody>';

    // Trier les mois
    ksort($totalWorkingHoursByMonth);

    // Tableau pour les noms des mois en français
    $moisFrancais = [
        '01' => 'Janvier',
        '02' => 'Février',
        '03' => 'Mars',
        '04' => 'Avril',
        '05' => 'Mai',
        '06' => 'Juin',
        '07' => 'Juillet',
        '08' => 'Août',
        '09' => 'Septembre',
        '10' => 'Octobre',
        '11' => 'Novembre',
        '12' => 'Décembre'
    ];

    foreach ($totalWorkingHoursByMonth as $month => $workingHours) {
        $extraHours = isset($totalExtraHoursByMonth[$month]) ? $totalExtraHoursByMonth[$month] : 0;
        // Formater le mois en français
        $dateObj = DateTime::createFromFormat('Y-m', $month);
        $monthNumber = $dateObj->format('m');
        $year = $dateObj->format('Y');
        $formattedMonth = $moisFrancais[$monthNumber] . ' ' . $year;

        $htmlMonthly .= '<tr>
        <td>' . $formattedMonth . '</td>
        <td>' . round($workingHours, 2) . '</td>
        <td>' . $extraHours . '</td>
        </tr>';
    }

    $htmlMonthly .= '</tbody></table>';

    $pdfCalendar->writeHTML($htmlMonthly);
}

// --- Étape 7 : Exporter les Trois PDFs dans un Dossier ---
ob_end_clean();  // Nettoyer le tampon de sortie

// Définir le chemin du dossier de sortie
$dossierPdfs = __DIR__ . '/dossiers_pdfs';
if (!is_dir($dossierPdfs)) {
    mkdir($dossierPdfs, 0777, true);  // Créer le dossier s'il n'existe pas
}

// Chemin des fichiers PDF
$cheminPdfSms = $dossierPdfs . '/calendrier_sms.pdf';  // Premier PDF
$cheminPdfAmplitude = $dossierPdfs . '/amplitude_travail.pdf';  // Second PDF
$cheminPdfCalendar = $dossierPdfs . '/calendrier_travail.pdf';  // Troisième PDF

// Sauvegarder les PDFs
$pdfSms->Output($cheminPdfSms, 'F');  // Enregistrer sur le serveur
$pdfAmplitude->Output($cheminPdfAmplitude, 'F');  // Enregistrer sur le serveur
$pdfCalendar->Output($cheminPdfCalendar, 'F');  // Enregistrer sur le serveur

exit;
?>
