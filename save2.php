<?php
require_once('vendor/autoload.php'); // Inclure TCPDF via autoload

// --- Étape 1 : Lecture des fichiers XML et extraction des SMS/MMS/Appels ---
$numeroRecherche = ['0660839365', '+33660839365', '0622913131', '+33622913131']; // Numéros à rechercher
$smsByDay = [];  // Tableau regroupant les SMS/MMS/Appels par jour

// Fonction pour lire les fichiers XML
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
        }
    }
    $reader->close();
}

// Fonction pour ajouter un SMS dans le tableau
function ajouterSmsAuTableau(&$smsByDay, $reader) {
    $date = (float)$reader->getAttribute('date'); // Convertir en float
    $timestamp = round($date / 1000);  // Conversion explicite avec arrondi
    $dayKey = date('Y-m-d', $timestamp);  // Regrouper les messages par jour

    $smsByDay[$dayKey][] = [
        'type' => 'SMS',
        'timestamp' => $timestamp
    ];
}

// Fonction pour ajouter un MMS
function ajouterMmsAuTableau(&$smsByDay, $reader) {
    $date = (float)$reader->getAttribute('date'); // Convertir en float
    $timestamp = round($date / 1000);  // Conversion explicite avec arrondi
    $dayKey = date('Y-m-d', $timestamp);

    $smsByDay[$dayKey][] = [
        'type' => 'MMS',
        'timestamp' => $timestamp
    ];
}

// Fonction pour ajouter un appel
function ajouterCallAuTableau(&$smsByDay, $reader) {
    $date = (float)$reader->getAttribute('date'); // Convertir en float
    $timestamp = round($date / 1000);  // Conversion explicite avec arrondi
    $dayKey = date('Y-m-d', $timestamp);

    $smsByDay[$dayKey][] = [
        'type' => 'Call',
        'timestamp' => $timestamp
    ];
}

// Lecture des fichiers XML
lireFichierXML('smsPerso.xml', $smsByDay, $numeroRecherche);  // Premier fichier avec filtrage
lireFichierXML('sms-20240818155416.xml', $smsByDay);  // Deuxième fichier, sans filtrage
lireFichierXML('calls-20240818155416.xml', $smsByDay);  // Troisième fichier
lireFichierXML('smses_backup.xml', $smsByDay);  // Quatrième fichier

// --- Étape 2 : Générer le PDF avec le calendrier et les points ---

// Trier les jours par clé (date)
ksort($smsByDay);

// Fonction pour générer un calendrier pour un mois avec des points pour les événements
function ajouterCalendrierMensuelAvecPoints($pdf, $smsByDay, $annee, $mois) {
    $joursSemaine = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    $largeurCase = 277 / 7; // Largeur d'une case dans le calendrier (pour 7 jours par semaine)
    $hauteurCase = 190 / 6; // Hauteur d'une case dans le calendrier (6 lignes max pour les semaines)

    $pdf->AddPage('L');  // Chaque mois sur une nouvelle page en mode paysage
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, "Mois : " . DateTime::createFromFormat('!m', $mois)->format('F') . " $annee", 0, 1, 'C');
    $pdf->Ln(5);

    // Affichage des jours de la semaine
    foreach ($joursSemaine as $jourSemaineTexte) {
        $pdf->Cell($largeurCase, 10, $jourSemaineTexte, 1, 0, 'C');
    }
    $pdf->Ln();

    // Calculer le premier jour du mois
    $firstDay = new DateTime("$annee-$mois-01");
    $startDayOfWeek = (int)$firstDay->format('N'); // 1 = lundi, 7 = dimanche
    $daysInMonth = (int)$firstDay->format('t'); // Nombre de jours dans le mois

    $currentDayOfWeek = $startDayOfWeek;

    // Ajouter des cellules vides pour les jours avant le premier du mois
    for ($i = 1; $i < $startDayOfWeek; $i++) {
        $pdf->Cell($largeurCase, $hauteurCase, '', 1, 0, 'C');
    }

    // Ajouter les jours du mois
    for ($jour = 1; $jour <= $daysInMonth; $jour++) {
        $dateKey = "$annee-$mois-" . str_pad($jour, 2, '0', STR_PAD_LEFT);

        // Afficher le numéro du jour
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell($largeurCase, $hauteurCase, $jour, 1, 0, 'L');

        // Ajouter des points si des événements sont présents pour ce jour
        if (array_key_exists($dateKey, $smsByDay)) {
            $pdf->SetFont('helvetica', '', 10);
            $nbEvenements = count($smsByDay[$dateKey]);
            $points = str_repeat('• ', $nbEvenements); // Génère autant de points qu'il y a d'événements
            $pdf->SetXY($pdf->GetX() - $largeurCase + 2, $pdf->GetY() + 5); // Ajuste la position pour les points
            $pdf->MultiCell($largeurCase - 4, 5, $points, 0, 'L');
        }

        // Si on arrive à la fin de la semaine (dimanche), passer à la ligne suivante
        if ($currentDayOfWeek == 7) {
            $pdf->Ln();
            $currentDayOfWeek = 1; // Recommence à lundi
        } else {
            $currentDayOfWeek++;
        }
    }

    // Compléter les cellules restantes jusqu'à la fin de la semaine
    while ($currentDayOfWeek <= 7) {
        $pdf->Cell($largeurCase, $hauteurCase, '', 1, 0, 'C');
        $currentDayOfWeek++;
    }

    $pdf->Ln();
}

// Récupérer la première et dernière date des messages
$dates = array_keys($smsByDay);
if (empty($dates)) {
    echo "Aucune donnée de date trouvée.";
    exit;
}

sort($dates);
$firstDate = new DateTime($dates[0]);
$lastDate = new DateTime(end($dates));
$interval = DateInterval::createFromDateString('1 month');
$period = new DatePeriod($firstDate, $interval, $lastDate->modify('last day of this month'));

// Créer le PDF
$pdf = new TCPDF();
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Anaïs Leblanc');
$pdf->SetTitle('Calendrier avec points');
$pdf->SetSubject('Calendrier mensuel avec événements');

// Générer le calendrier avec des points pour chaque mois dans la période
foreach ($period as $monthDate) {
    $annee = $monthDate->format('Y');
    $mois = $monthDate->format('m');
    ajouterCalendrierMensuelAvecPoints($pdf, $smsByDay, $annee, $mois);
}

// Sauvegarder le fichier PDF
$cheminPdf = __DIR__ . '/calendrier_mensuel_avec_points.pdf';
ob_end_clean();
$pdf->Output($cheminPdf, 'F');

echo 'PDF généré : ' . $cheminPdf;
