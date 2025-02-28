<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2021 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2021
 */


namespace App\Util;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Entity\Genitore;
use App\Entity\Docente;
use App\Entity\Classe;
use App\Entity\Cattedra;


/**
 * ArchiviazioneUtil - classe di utilità per le funzioni per l'archiviazione
 */
class ArchiviazioneUtil {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var EntityManagerInterface $em Gestore delle entità
   */
  private $em;

  /**
   * @var TranslatorInterface $trans Gestore delle traduzioni
   */
  private $trans;

  /**
   * @var SessionInterface $session Gestore delle sessioni
   */
  private $session;

  /**
   * @var PdfManager $pdf Gestore dei documenti PDF
   */
  private $pdf;

  /**
   * @var RegistroUtil $regUtil Funzioni di utilità per il registro
   */
  private $regUtil;

  /**
   * @var PagelleUtil $pag Funzioni di utilità per le pagelle
   */
  private $pag;

  /**
   * @var string $root Directory principale di archiviazione
   */
  private $root;

  /**
   * @var string $dirCircolari Directory delle circolari
   */
  private $dirCircolari;

  /**
   * @var string $localpath Directory per le immagini locali
   */
  private $localpath;


  //==================== METODI DELLA CLASSE ====================

  /**
   * Construttore
   *
   * @param EntityManagerInterface $em Gestore delle entità
   * @param TranslatorInterface $trans Gestore delle traduzioni
   * @param SessionInterface $session Gestore delle sessioni
   * @param RegistroUtil $regUtil Funzioni di utilità per il registro
   * @param PagelleUtil $pag Funzioni di utilità per le pagelle
   * @param PdfManager $pdf Gestore dei documenti PDF
   * @param string $root Directory principale di archiviazione
   * @param string $dirCircolari Directory delle circolari
   * @param string $localpath Directory per le immagini locali
   */
  public function __construct(EntityManagerInterface $em, TranslatorInterface $trans,
                               SessionInterface $session, PdfManager $pdf, RegistroUtil $regUtil,
                               PagelleUtil $pag, $root, $dirCircolari, $localpath) {
    $this->em = $em;
    $this->trans = $trans;
    $this->session = $session;
    $this->pdf = $pdf;
    $this->regUtil = $regUtil;
    $this->pag = $pag;
    $this->root = $root;
    $this->dirCircolari = $dirCircolari;
    $this->localpath = $localpath;
  }

  /**
   * Crea il registro del docente
   *
   * @param Docente $docente Docente di cui creare il registro personale
   */
  public function registroDocente(Docente $docente) {
    // inizializza
    $fs = new Filesystem();
    // percorso destinazione
    $percorso = $this->root.'/registri/docenti';
    if (!$fs->exists($percorso)) {
      // crea directory
      $fs->mkdir($percorso, 0775);
    }
    // nome documento
    $nomefile = 'registro-docente-'.mb_strtoupper($docente->getCognome(), 'UTF-8').'-'.
      mb_strtoupper($docente->getNome(), 'UTF-8').'.pdf';
    $nomefile = str_replace(['À','È','É','Ì','Ò','Ù',' ','"','\'','`'],
                            ['A','E','E','I','O','U','-','' ,''  ,'' ], $nomefile);
    // lista cattedre
    $cattedre = $this->em->getRepository('App:Cattedra')->createQueryBuilder('c')
      ->join('c.docente', 'd')
      ->join('c.materia', 'm')
      ->join('c.classe', 'cl')
      ->where('d.id=:docente AND m.tipo IN (:tipi)')
      ->orderBy('cl.anno,cl.sezione,m.ordinamento', 'ASC')
      ->setParameters(['docente' => $docente, 'tipi' => ['N', 'R', 'E']])
      ->getQuery()
      ->getResult();
    if (empty($cattedre)) {
      // errore
      $this->session->getFlashBag()->add('danger', 'Il docente '.$docente->getCognome().' '.$docente->getNome().
        ' non è associato a nessuna cattedra.');
      return;
    }
    // crea documento
    $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
      'Registro del docente - '.$docente->getNome().' '.$docente->getCognome());
    // impostazioni PDF
    $this->pdf->getHandler()->SetMargins(10, 15, 10, true);
    $this->pdf->getHandler()->SetAutoPageBreak(true, 15);
    $this->pdf->getHandler()->SetFont('helvetica', '', 10);
    $this->pdf->getHandler()->setPrintHeader(false);
    $this->pdf->getHandler()->SetFooterMargin(12);
    $this->pdf->getHandler()->setFooterFont(Array('helvetica', '', 8));
    $this->pdf->getHandler()->setFooterData(array(0,0,0), array(255,255,255));
    $this->pdf->getHandler()->setPrintFooter(true);
    // scansione cattedre
    foreach ($cattedre as $cat) {
      // inizializza
      $this->copertinaRegistroDocente($docente, $cat);
      $pagina = $this->pdf->getHandler()->PageNo();
      // primo periodo
      $this->scriveRegistroDocente($docente, $cat, 1);
      // secondo periodo
      $this->scriveRegistroDocente($docente, $cat, 2);
      // controlla dati presenti
      if ($pagina == $this->pdf->getHandler()->PageNo()) {
        // stessa pagina: nessun dato aggiunto
        $this->pdf->getHandler()->deletePage($pagina);
      }
    }
    // salva il documento
    if ($this->pdf->getHandler()->PageNo() > 0) {
      $this->pdf->save($percorso.'/'.$nomefile);
      // registro creato
      $this->session->getFlashBag()->add('success', 'Registro del docente '.$docente->getCognome().' '.$docente->getNome().
        ' archiviato.');
    } else {
      // registro non creato
      $this->session->getFlashBag()->add('warning', 'Registro del docente '.$docente->getCognome().' '.$docente->getNome().
        ' non creato per mancanza di dati.');
    }
  }

  /**
   * Crea tutti i registri dei docenti
   *
   * @param Array $docenti Lista dei docenti di cui creare il registro personale
   */
  public function tuttiRegistriDocente($docenti) {
    foreach ($docenti as $doc) {
      $this->registroDocente($doc);
    }
  }

  /**
   * Crea il registro di sostegno
   *
   * @param Docente $docente Docente di cui creare il registro di sostegno
   */
  public function registroSostegno(Docente $docente) {
    // inizializza
    $fs = new Filesystem();
    // percorso destinazione
    $percorso = $this->root.'/registri/sostegno';
    if (!$fs->exists($percorso)) {
      // crea directory
      $fs->mkdir($percorso, 0775);
    }
    // nome documento
    $nomefile = 'registro-sostegno-'.mb_strtoupper($docente->getCognome(), 'UTF-8').'-'.
      mb_strtoupper($docente->getNome(), 'UTF-8').'.pdf';
    $nomefile = str_replace(['À','È','É','Ì','Ò','Ù',' ','"','\'','`'],
                            ['A','E','E','I','O','U','-','' ,''  ,'' ], $nomefile);
    // lista cattedre
    $cattedre = $this->em->getRepository('App:Cattedra')->createQueryBuilder('c')
      ->join('c.docente', 'd')
      ->join('c.materia', 'm')
      ->join('c.classe', 'cl')
      ->join('c.alunno', 'a')
      ->where('d.id=:docente AND m.tipo=:tipo')
      ->orderBy('cl.anno,cl.sezione,a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['docente' => $docente, 'tipo' => 'S'])
      ->getQuery()
      ->getResult();
    if (empty($cattedre)) {
      // errore
      $this->session->getFlashBag()->add('danger', 'Il docente '.$docente->getCognome().' '.$docente->getNome().
        ' non è associato a nessuna cattedra.');
      return;
    }
    // crea documento
    $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
      'Registro di sostegno - '.$docente->getNome().' '.$docente->getCognome());
    // impostazioni PDF
    $this->pdf->getHandler()->SetMargins(10, 15, 10, true);
    $this->pdf->getHandler()->SetAutoPageBreak(true, 15);
    $this->pdf->getHandler()->SetFont('helvetica', '', 10);
    $this->pdf->getHandler()->setPrintHeader(false);
    $this->pdf->getHandler()->SetFooterMargin(12);
    $this->pdf->getHandler()->setFooterFont(Array('helvetica', '', 8));
    $this->pdf->getHandler()->setFooterData(array(0,0,0), array(255,255,255));
    $this->pdf->getHandler()->setPrintFooter(true);
    // scansione cattedre
    foreach ($cattedre as $cat) {
      // inizializza
      $this->copertinaRegistroSostegno($docente, $cat);
      $pagina = $this->pdf->getHandler()->PageNo();
      // primo periodo
      $this->scriveRegistroSostegno($docente, $cat, 1);
      // secondo periodo
      $this->scriveRegistroSostegno($docente, $cat, 2);
      // controlla dati presenti
      if ($pagina == $this->pdf->getHandler()->PageNo()) {
        // stessa pagina: nessun dato aggiunto
        $this->pdf->getHandler()->deletePage($pagina);
      }
    }
    // salva il documento
    if ($this->pdf->getHandler()->PageNo() > 0) {
      $this->pdf->save($percorso.'/'.$nomefile);
      // registro creato
      $this->session->getFlashBag()->add('success', 'Registro di sostegno di '.$docente->getCognome().' '.$docente->getNome().
        ' archiviato.');
    } else {
      // registro non creato
      $this->session->getFlashBag()->add('warning', 'Registro di sostegno di '.$docente->getCognome().' '.$docente->getNome().
        ' non creato per mancanza di dati.');
    }
  }

  /**
   * Crea tutti i registri di sostegno
   *
   * @param Array $docenti Lista dei docenti di cui creare il registro di sostegno
   */
  public function tuttiRegistriSostegno($docenti) {
    foreach ($docenti as $doc) {
      $this->registroSostegno($doc);
    }
  }

  /**
   * Crea il registro di classe
   *
   * @param Classe $classe Classe di cui creare il registro
   */
  public function registroClasse(Classe $classe) {
    // inizializza
    $fs = new Filesystem();
    // percorso destinazione
    $percorso = $this->root.'/registri/classi';
    if (!$fs->exists($percorso)) {
      // crea directory
      $fs->mkdir($percorso, 0775);
    }
    // nome documento
    $nomefile = 'registro-classe-'.$classe->getAnno().$classe->getSezione().'.pdf';
    // crea documento
    $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
      'Registro di classe - '.$classe->getAnno().'ª '.$classe->getSezione());
    // impostazioni PDF
    $this->pdf->getHandler()->SetMargins(10, 15, 10, true);
    $this->pdf->getHandler()->SetAutoPageBreak(true, 15);
    $this->pdf->getHandler()->SetFont('helvetica', '', 10);
    $this->pdf->getHandler()->setPrintHeader(false);
    $this->pdf->getHandler()->SetFooterMargin(12);
    $this->pdf->getHandler()->setFooterFont(Array('helvetica', '', 8));
    $this->pdf->getHandler()->setFooterData(array(0,0,0), array(255,255,255));
    $this->pdf->getHandler()->setPrintFooter(true);
    // primo periodo
    $this->copertinaRegistroClasse($classe, 1);
    $this->scriveRegistroClasse($classe, 1);
    // secondo periodo
    $this->copertinaRegistroClasse($classe, 2);
    $this->scriveRegistroClasse($classe, 2);
    // salva il documento
    $this->pdf->save($percorso.'/'.$nomefile);
    // registro creato
    $this->session->getFlashBag()->add('success', 'Registro di classe '.$classe->getAnno().'ª '.$classe->getSezione().
      ' archiviato.');
  }

  /**
   * Crea tutti i registri di classe
   *
   * @param Array $classi Lista delle classi di cui creare il registro di classe
   */
  public function tuttiRegistriClasse($classi) {
    foreach ($classi as $cl) {
      $this->registroClasse($cl);
    }
  }

  /**
   * Crea la pagina iniziale del registro del docente
   *
   * @param Docente $docente Docente di cui creare il registro
   * @param Cattedra $cattedra Cattedra del docente
   */
  public function copertinaRegistroDocente(Docente $docente, Cattedra $cattedra) {
    // nuova pagina
    $this->pdf->getHandler()->AddPage('L');
    // crea copertina
    $this->pdf->getHandler()->SetFont('times', '', 14);
    $html = '
      <div style="text-align:center">
        <img src="/img/'.$this->localpath.'intestazione-documenti.jpg" width="600">
      </div>';
    $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    $this->pdf->getHandler()->SetFont('helvetica', 'B', 18);
    $annoscolastico = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $docente_s = $docente->getNome().' '.$docente->getCognome();
    $classe_s = $cattedra->getClasse()->getAnno().'ª '.$cattedra->getClasse()->getSezione();
    $corso_s = $cattedra->getClasse()->getCorso()->getNome().' - Sede di '.$cattedra->getClasse()->getSede()->getCitta();
    $materia_s = $cattedra->getMateria()->getNome();
    $html = '<br>
           <p>A.S. '.$annoscolastico.'</p>
           <p style="font-size:15pt">Registro del docente<br><span style="font-size:20pt">'.$docente_s.'</span></p>
           <p>Classe '.$classe_s.'<br><span style="font-size:15pt">'.$corso_s.'</span></p>
           <p><i>'.$materia_s.'</i></p>';
    $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    // reset carattere
    $this->pdf->getHandler()->SetFont('helvetica', '', 10);
  }

  /**
   * Scrive le lezioni del docente, con argomenti e voti e osservazioni
   *
   * @param Docente $docente Docente di cui creare il registro
   * @param Cattedra $cattedra Cattedra del docente
   * @param int $periodo Periodo di riferimento
   */
  public function scriveRegistroDocente(Docente $docente, Cattedra $cattedra, $periodo) {
    // inizializza dati
    $docente_s = $docente->getNome().' '.$docente->getCognome();
    $docente_sesso = $docente->getSesso();
    $classe_s = $cattedra->getClasse()->getAnno().'ª '.$cattedra->getClasse()->getSezione();
    $corso_s = $cattedra->getClasse()->getCorso()->getNome().' - Sede di '.$cattedra->getClasse()->getSede()->getCitta();
    $materia_s = $cattedra->getMateria()->getNome();
    $dati_periodi = $this->regUtil->infoPeriodi();
    $periodo_s = $dati_periodi[$periodo]['nome'];
    $annoscolastico = $this->session->get('/CONFIG/SCUOLA/anno_scolastico').
      ' - '.$periodo_s;
    $nomemesi = array('', 'GEN','FEB','MAR','APR','MAG','GIU','LUG','AGO','SET','OTT','NOV','DIC');
    $nomesett = array('Dom','Lun','Mar','Mer','Gio','Ven','Sab');
    $info_voti['N'] = [0 => 'N.C.', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['E'] = [3 => 'N.C.', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'N.C.', 21 => 'Insuff.', 22 => 'Suff.', 23 => 'Discr.', 24 => 'Buono', 25 => 'Dist.', 26 => 'Ottimo'];
    $dati['lezioni'] = array();
    $dati['argomenti'] = array();
    $dati['voti'] = array();
    $dati['alunni'] = array();
    $dati['osservazioni'] = array();
    $dati['personali'] = array();
    // ore totali (in unità orarie, non minuti effettivi)
    $ore = $this->em->getRepository('App:Lezione')->createQueryBuilder('l')
      ->select('SUM(so.durata)')
      ->join('App:Firma', 'f', 'WITH', 'l.id=f.lezione AND f.docente=:docente')
      ->join('App:ScansioneOraria', 'so', 'WITH', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
      ->join('so.orario', 'o')
      ->where('l.classe=:classe AND l.materia=:materia AND l.data BETWEEN :inizio AND :fine AND l.data BETWEEN o.inizio AND o.fine AND o.sede=:sede')
      ->setParameters(['docente' => $docente, 'classe' => $cattedra->getClasse(), 'materia' => $cattedra->getMateria(),
        'inizio' => $dati_periodi[$periodo]['inizio'], 'fine' => $dati_periodi[$periodo]['fine'],
        'sede' => $cattedra->getClasse()->getSede()])
      ->getQuery()
      ->getSingleScalarResult();
    $ore = rtrim(rtrim(number_format($ore, 1, ',', ''), '0'), ',');
    if ($ore > 0) {
      // legge lezioni del periodo
      $lezioni = $this->em->getRepository('App:Lezione')->createQueryBuilder('l')
        ->select('l.id,l.data,l.ora,so.durata,l.argomento,l.attivita')
        ->join('App:Firma', 'f', 'WITH', 'l.id=f.lezione AND f.docente=:docente')
        ->join('App:ScansioneOraria', 'so', 'WITH', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
        ->join('so.orario', 'o')
        ->where('l.classe=:classe AND l.materia=:materia AND l.data BETWEEN :inizio AND :fine AND l.data BETWEEN o.inizio AND o.fine AND o.sede=:sede')
        ->orderBy('l.data,l.ora', 'ASC')
        ->setParameters(['docente' => $docente, 'classe' => $cattedra->getClasse(), 'materia' => $cattedra->getMateria(),
          'inizio' => $dati_periodi[$periodo]['inizio'], 'fine' => $dati_periodi[$periodo]['fine'],
          'sede' => $cattedra->getClasse()->getSede()])
        ->getQuery()
        ->getArrayResult();
      // legge assenze/voti
      $lista = array();
      $lista_alunni = array();
      $data_prec = null;
      $giornilezione = array();
      foreach ($lezioni as $l) {
        if (!$data_prec || $l['data'] != $data_prec) {
          // cambio di data
          $giornilezione[] = $l['data'];
          $mese = (int) $l['data']->format('m');
          $giorno = (int) $l['data']->format('d');
          $dati['lezioni'][$mese][$giorno]['durata'] = 0;
          $lista = $this->regUtil->alunniInData($l['data'], $cattedra->getClasse());
          $lista_alunni = array_unique(array_merge($lista_alunni, $lista));
          // alunni in classe per data
          foreach ($lista as $id) {
            $dati['lezioni'][$mese][$giorno][$id]['classe'] = 1;
          }
        }
        // aggiorna durata lezioni
        $dati['lezioni'][$mese][$giorno]['durata'] += $l['durata'];
        // legge assenze
        $assenze = $this->em->getRepository('App:AssenzaLezione')->createQueryBuilder('al')
          ->select('(al.alunno) AS id,al.ore')
          ->where('al.lezione=:lezione')
          ->setParameters(['lezione' => $l['id']])
          ->getQuery()
          ->getArrayResult();
        // somma ore di assenza per alunno
        foreach ($assenze as $a) {
          if (isset($dati['lezioni'][$mese][$giorno][$a['id']]['assenze'])) {
            $dati['lezioni'][$mese][$giorno][$a['id']]['assenze'] += $a['ore'];
          } else {
            $dati['lezioni'][$mese][$giorno][$a['id']]['assenze'] = $a['ore'];
          }
        }
        // legge voti
        $voti = $this->em->getRepository('App:Valutazione')->createQueryBuilder('v')
          ->select('(v.alunno) AS id,v.id AS voto_id,v.tipo,v.visibile,v.voto,v.giudizio,v.argomento')
          ->where('v.lezione=:lezione AND v.docente=:docente')
          ->setParameters(['lezione' => $l['id'], 'docente' => $docente])
          ->getQuery()
          ->getArrayResult();
        // voti per alunno
        foreach ($voti as $v) {
          if ($v['voto'] > 0) {
            $voto_int = (int) ($v['voto'] + 0.25);
            $voto_dec = $v['voto'] - ((int) $v['voto']);
            $v['voto_str'] = $voto_int.($voto_dec == 0.25 ? '+' : ($voto_dec == 0.75 ? '-' : ($voto_dec == 0.5 ? '½' : '')));
          }
          $dati['lezioni'][$mese][$giorno][$v['id']]['voti'][] = $v;
          $dati['voti'][$v['id']][$l['data']->format('d/m/Y')][] = $v;
        }
        // memorizza data precedente
        $data_prec = $l['data'];
      }
      // lista alunni (ordinata)
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.cognome,a.nome,a.dataNascita,a.religione,(a.classe) AS idclasse')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' => $lista_alunni])
        ->getQuery()
        ->getArrayResult();
      foreach ($alunni as $alu) {
        $dati['alunni'][$alu['id']] = $alu;
        $dati['alunni'][$alu['id']]['assenze'] = 0;
      }
      // legge le proposte di voto
      $proposte = $this->em->getRepository('App:PropostaVoto')->createQueryBuilder('pv')
        ->select('(pv.alunno) AS idalunno,pv.unico')
        ->where('pv.alunno IN (:alunni) AND pv.classe=:classe AND pv.materia=:materia AND pv.periodo=:periodo')
        ->setParameters(['alunni' => $lista_alunni, 'classe' => $cattedra->getClasse(),
          'materia' => $cattedra->getMateria(), 'periodo' => ($periodo == 1 ? 'P' : 'F')]);
      if ($cattedra->getMateria()->getTipo() == 'E') {
        // proposte multiple per Ed.civica: aggiunge condizione su docente
        $proposte = $proposte
          ->andWhere('pv.docente=:docente')
          ->setParameter('docente', $docente);
      }
      $proposte = $proposte
        ->getQuery()
        ->getArrayResult();
      foreach ($proposte as $p) {
        // inserisce proposte trovate
        $dati['alunni'][$p['idalunno']]['proposte'] = $p['unico'];
      }
      // imposta lezioni per pagina
      $aluritirati = false;
      $numerotbl_lezioni = count($giornilezione);
      $lezperpag = 20;
      $colfinali = 4; // proposte voto e assenze
      $colresidue = ($numerotbl_lezioni + $colfinali) % $lezperpag;
      $numeropagine = (int)(($numerotbl_lezioni + $colfinali) / $lezperpag);
      if ($colresidue > 0) {
        $numeropagine++;
      }
      // cicla per ogni pagina
      for ($np = 0; $np < $numeropagine; $np++) {
        // intestazione di pagina
        $this->intestazionePagina('Lezioni della classe', $docente_s, $classe_s, $corso_s, $materia_s, $annoscolastico);
        if ($np == 0) {
          $html = '<br>Totale ore di lezione: '.$ore;
          $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
        }
        // intestazione tabella
        $html_col = '';
        $html_inizio = '<table border="1"><tr><td style="width:75mm"><b>Alunno</b></td>';
        $html_inizio_rs = '<table border="1"><tr><td style="width:75mm" rowspan="2"><b>Alunno</b></td>';
        $html = '';
        for ($ng = $np * $lezperpag; $ng < min(($np + 1) * $lezperpag, $numerotbl_lezioni); $ng++) {
          $g = $giornilezione[$ng];
          $gs = $nomesett[$g->format('w')];
          $gg = $g->format('j');
          $gm = $nomemesi[$g->format('n')];
          $strore = rtrim(rtrim(number_format($dati['lezioni'][$g->format('n')][$g->format('j')]['durata'], 1, ',', ''), '0'), ',');
          $html .= '<td style="width:10mm"><b>'.$gs.'<br>'.$gg.'<br>'.$gm.'</b></td>';
          $html_col .= '<td><i>'.$strore.'</i></td>';
        }
        if ($np == $numeropagine - 1) {
          $rspan = ($html_col == '' ? '' : ' rowspan="2"');
          $html .= '<td style="width:20mm"'.$rspan.'><b>Totale<br>ore di<br>assenza</b></td>';
          $html .= '<td style="width:20mm"'.$rspan.'><b>Proposte<br>di voto</b></td>';
        }
        $html = ($html_col == '' ? $html_inizio : $html_inizio_rs).$html.'</tr>'.
          ($html_col == '' ? '' : '<tr>'.$html_col.'</tr>');
        // dati alunni
        foreach ($dati['alunni'] as $idalu=>$alu) {
          // controllo materia religione
          if ($cattedra->getTipo() != 'A' && $cattedra->getMateria()->getTipo() == 'R' && $alu['religione'] != 'S') {
            // materia religione e alunno non si avvale
            continue;
          }
          if ($cattedra->getTipo() == 'A' && $cattedra->getMateria()->getTipo() == 'R' && $alu['religione'] != 'A') {
            // materia alternativa alla religione e alunno non si avvale
            continue;
          }
          // nome
          $html .= '<tr nobr="true" style="font-size:9pt">'.
            '<td align="left"> '.
            ($alu['idclasse'] != $cattedra->getClasse()->getId() ? '* ' : '').
            $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')'.
            '</td>';
          if ($alu['idclasse'] != $cattedra->getClasse()->getId()) {
            // segnala presenza di alunni ritirati
            $aluritirati = true;
          }
          // assenze e voti
          for ($ng = $np * $lezperpag; $ng < min(($np + 1) * $lezperpag, $numerotbl_lezioni); $ng++) {
            $g = $giornilezione[$ng];
            $gg = $g->format('j');
            $gm = $g->format('n');
            if (isset($dati['lezioni'][$gm][$gg][$idalu]['classe'])) {
              // alunno inserito in classe
              $html .= '<td>';
              // assenze
              if (isset($dati['lezioni'][$gm][$gg][$idalu]['assenze'])) {
                $ass = $dati['lezioni'][$gm][$gg][$idalu]['assenze'];
                $html .= str_repeat('A', intval($ass)).(($ass - intval($ass)) > 0 ? 'a' : '');
                $dati['alunni'][$idalu]['assenze'] += $ass;
              }
              // voti
              if (isset($dati['lezioni'][$gm][$gg][$idalu]['voti'])) {
                foreach ($dati['lezioni'][$gm][$gg][$idalu]['voti'] as $voti) {
                  if (isset($voti['voto_str'])) {
                    $html .= ' <b>'.$voti['voto_str'].'</b><sub>&nbsp;'.$voti['tipo'].'</sub>';
                  }
                }
              }
              $html .= '</td>';
            } else {
              // alunno non inserito in classe
              $html .= '<td style="background-color:#CCCCCC">&nbsp;</td>';
            }
          }
          if ($np == $numeropagine - 1) {
            // tot. assenze
            $html .= '<td>'.rtrim(rtrim(number_format($dati['alunni'][$idalu]['assenze'], 1, ',', ''), '0'), ',').'</td>';
            // proposte voto
            if (isset($dati['alunni'][$idalu]['proposte'])) {
              $html .= '<td><b>'.$info_voti[$cattedra->getMateria()->getTipo()][$dati['alunni'][$idalu]['proposte']].'</b></td>';
            } else {
              $html .= '<td style="width:20mm">&nbsp;</td>';
            }
          }
          $html .= '</tr>';
        }
        // fine tabella
        $html .= '</table>';
        $this->pdf->getHandler()->writeHTML($html, false, false, false, false, 'C');
        if ($html_col == '') {
          $html = '';
        } else {
          $html =
            '<b>A</b> = assenza di un\'ora; <b>a</b> = assenza di mezzora; '.
            '<b>S</b> = voto scritto; <b>O</b> = voto orale; <b>P</b> = voto pratico';
        }
        if ($aluritirati) {
          $html .= '<br><b>*</b> Alunno ritirato/trasferito/frequenta l\'anno all\'estero';
        }
        $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
      }
      // legge argomenti e attività
      $data_prec = null;
      $num_arg = 0;
      $num_att = 0;
      foreach ($lezioni as $l) {
        $data = $l['data']->format('d/m/Y');
        if ($data_prec && $data != $data_prec) {
          if ($num_arg == 0) {
            // nessun argomento in data precedente
            $dati['argomenti'][$data_prec]['argomento'][0] = '';
          }
          if ($num_att == 0) {
            // nessuna attività in data precedente
            $dati['argomenti'][$data_prec]['attivita'][0] = '';
          }
          // fa ripartire contatori
          $num_arg = 0;
          $num_att = 0;
        }
        $testo = $this->ripulisceTesto($l['argomento']);
        if ($testo != '' && ($num_arg == 0 ||
            strcasecmp($testo, $dati['argomenti'][$data]['argomento'][$num_arg - 1]) != 0)) {
          // evita ripetizioni identiche degli argomenti
          $dati['argomenti'][$data]['argomento'][$num_arg] = $testo;
          $num_arg++;
        }
        $testo = $this->ripulisceTesto($l['attivita']);
        if ($testo != '' && ($num_att == 0 ||
            strcasecmp($testo, $dati['argomenti'][$data]['attivita'][$num_att - 1]) != 0)) {
          // evita ripetizioni identiche delle attività
          $dati['argomenti'][$data]['attivita'][$num_att] = $testo;
          $num_att++;
        }
        // memorizza data attuale
        $data_prec = $data;
      }
      if ($data_prec && $num_arg == 0) {
          // nessun argomento in data precedente
          $dati['argomenti'][$data_prec]['argomento'][0] = '';
      }
      if ($data_prec && $num_att == 0) {
        // nessuna attività in data precedente
        $dati['argomenti'][$data_prec]['attivita'][0] = '';
      }
      // scrive argomenti e attività
      $this->intestazionePagina('Argomenti e attivit&agrave; della classe', $docente_s, $classe_s, $corso_s, $materia_s, $annoscolastico);
      $html = '<table border="1" style="left-padding:2mm">
        <tr>
          <td style="width:10%"><b>Data</b></td>
          <td style="width:45%"><b>Argomenti</b></td>
          <td style="width:45%"><b>Attivit&agrave;</b></td>
        </tr>';
      foreach ($dati['argomenti'] as $d=>$arg) {
        $html .= '<tr nobr="true"><td>'.$d.'</td>'.
          '<td align="left">'.implode('<br>', $arg['argomento']).'</td>'.
          '<td align="left">'.implode('<br>', $arg['attivita']).'</td>'.
          '</tr>';
      }
      $html .= '</table>';
      $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
      // scrive dettaglio voti
      if (count($dati['voti']) > 0) {
        $this->intestazionePagina('Valutazioni della classe', $docente_s, $classe_s, $corso_s, $materia_s, $annoscolastico);
        foreach ($dati['alunni'] as $idalu=>$alu) {
          if (!isset($dati['voti'][$idalu])) {
            // alunno senza voti
            continue;
          }
          $html = '<table  cellpadding="2" style="font-size:10pt" nobr="true">
            <tr nobr="true">
              <td align="center" colspan="5"><strong>'.$alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')'.'</strong></td>
            </tr>
            <tr nobr="true">
              <td width="10%" style="border:1pt solid #000"><strong>Data</strong></td>
              <td width="8%" style="border:1pt solid #000"><strong>Tipo</strong></td>
              <td width="40%" style="border:1pt solid #000"><strong>Argomenti o descrizione della prova</strong></td>
              <td width="6%" style="border:1pt solid #000"><strong>Voto</strong></td>
              <td width="36%" style="border:1pt solid #000"><strong>Giudizio</strong></td>
            </tr>';
          foreach ($dati['voti'][$idalu] as $dt=>$vv) {
            foreach ($vv as $v) {
              $argomento = $this->ripulisceTesto($v['argomento']);
              $giudizio = $this->ripulisceTesto($v['giudizio']);
              $html .= '<tr nobr="true">'.
                  '<td style="border:1pt solid #000">'.$dt.'</td>'.
                  '<td style="border:1pt solid #000">'.($v['tipo'] == 'S' ? 'Scritto' : ($v['tipo'] == 'O' ? 'Orale' : 'Pratico')).'</td>'.
                  '<td style="border:1pt solid #000;font-size:9pt;text-align:left">'.$argomento.'</td>'.
                  '<td style="border:1pt solid #000"><strong>'.(isset($v['voto_str']) ? $v['voto_str'] : '').'</strong></td>'.
                  '<td style="border:1pt solid #000;font-size:9pt;text-align:left">'.$giudizio.'</td>'.
                '</tr>';
            }
          }
          $html .= '</table>';
          $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
        }
      }
    }
    // legge osservazioni sugli alunni
    $osservazioni = $this->em->getRepository('App:OsservazioneAlunno')->createQueryBuilder('o')
      ->select('o.data,o.testo,a.id AS alunno_id,a.cognome,a.nome,a.dataNascita')
      ->join('o.alunno', 'a')
      ->where('o.cattedra=:cattedra AND o.data BETWEEN :inizio AND :fine')
      ->orderBy('o.data,a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['cattedra' => $cattedra, 'inizio' => $dati_periodi[$periodo]['inizio'],
        'fine' => $dati_periodi[$periodo]['fine']])
      ->getQuery()
      ->getArrayResult();
    foreach ($osservazioni as $o) {
      $dati['osservazioni'][$o['data']->format('d/m/Y')][$o['alunno_id']][] = $o;
    }
    // scrive osservazioni sugli alunni
    if (count($osservazioni) > 0) {
      $this->intestazionePagina('Osservazioni sugli alunni della classe', $docente_s, $classe_s, $corso_s, $materia_s, $annoscolastico);
      // tabella osservazioni
      $html = '<table border="1" style="left-padding:2mm">
        <tr>
          <td style="width:10%"><b>Data</b></td>
          <td style="width:30%"><b>Alunno</b></td>
          <td style="width:60%"><b>Osservazioni</b></td>
        </tr>';
      foreach ($dati['osservazioni'] as $dt=>$oa) {
        foreach ($oa as $oo) {
          foreach ($oo as $oss) {
            $html .= '<tr nobr="true">'.
                '<td>'.$dt.'</td>'.
                '<td style="text-align:left">'.$oss['cognome'].' '.$oss['nome'].' ('.$oss['dataNascita']->format('d/m/Y').')'.'</td>'.
                '<td style="font-size:9pt;text-align:left">'.$this->ripulisceTesto($oss['testo']).'</td>'.
              '</tr>';
          }
        }
      }
      $html .= '</table>';
      $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    }
    // legge osservazioni personali
    $personali = $this->em->getRepository('App:OsservazioneClasse')->createQueryBuilder('o')
      ->select('o.data,o.testo')
      ->where('NOT (o INSTANCE OF App:OsservazioneAlunno) AND o.cattedra=:cattedra AND o.data BETWEEN :inizio AND :fine')
      ->orderBy('o.data', 'ASC')
      ->setParameters(['cattedra' => $cattedra, 'inizio' => $dati_periodi[$periodo]['inizio'],
        'fine' => $dati_periodi[$periodo]['fine']])
      ->getQuery()
      ->getArrayResult();
    foreach ($personali as $p) {
      $dati['personali'][$p['data']->format('d/m/Y')][] = $p;
    }
    // scrive osservazioni personali
    if (count($personali) > 0) {
      $this->intestazionePagina('Osservazioni sulla classe', $docente_s, $classe_s, $corso_s, $materia_s, $annoscolastico);
      // tabella osservazioni
      $html = '<table border="1" style="left-padding:2mm">
        <tr>
          <td style="width:10%"><b>Data</b></td>
          <td style="width:90%"><b>Osservazioni</b></td>
        </tr>';
      foreach ($dati['personali'] as $dt=>$o) {
        foreach ($o as $osp) {
          $html .= '<tr nobr="true">'.
              '<td>'.$dt.'</td>'.
              '<td style="font-size:9pt;text-align:left">'.$this->ripulisceTesto($osp['testo']).'</td>'.
            '</tr>';
        }
      }
      $html .= '</table>';
      $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    }
  }

  /**
   * Scrive l'intestazione della pagina del registro
   *
   * @param string $testo Testo per l'intestazione della pagina
   * @param string $docente Nome del docente
   * @param string $classe Indicazione della classe
   * @param string $corso Indicazione del corso
   * @param string $materia Indicazione della materia
   * @param string $annoscolastico Indicazione dell'anno scolastico
   */
  public function intestazionePagina($testo, $docente, $classe, $corso, $materia, $annoscolastico) {
    $this->pdf->getHandler()->AddPage('L');
    $html = '<b>A.S. '.$annoscolastico.'</b><br>'.
      $testo.' <b>'.$classe.' - '.$corso.'</b><br>'.
      ((!$docente || !$materia) ? '' : 'Materia: <b>'.$materia.'</b> &nbsp;&nbsp; - &nbsp;&nbsp; Docente: <b>'.$docente.'</b><br>');
    $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
  }

  /**
   * Crea la pagina iniziale del registro di sostegno
   *
   * @param Docente $docente Docente di cui creare il registro
   * @param Cattedra $cattedra Cattedra del docente
   */
  public function copertinaRegistroSostegno(Docente $docente, Cattedra $cattedra) {
    // nuova pagina
    $this->pdf->getHandler()->AddPage('L');
    // crea copertina
    $this->pdf->getHandler()->SetFont('times', '', 14);
    $html = '
      <div style="text-align:center">
        <img src="/img/'.$this->localpath.'intestazione-documenti.jpg" width="600">
      </div>';
    $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    $this->pdf->getHandler()->SetFont('helvetica', 'B', 18);
    $annoscolastico = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $docente_s = $docente->getNome().' '.$docente->getCognome();
    $classe_s = $cattedra->getClasse()->getAnno().'ª '.$cattedra->getClasse()->getSezione();
    $corso_s = $cattedra->getClasse()->getCorso()->getNome().' - Sede di '.$cattedra->getClasse()->getSede()->getCitta();
    $materia_s = 'Sostegno per '.$cattedra->getAlunno()->getCognome().' '.$cattedra->getAlunno()->getNome().
      ' ('.$cattedra->getAlunno()->getDataNascita()->format('d/m/Y').')';
    $html = '<br>
           <p>A.S. '.$annoscolastico.'</p>
           <p style="font-size:15pt">Registro di sostegno<br><span style="font-size:20pt">'.$docente_s.'</span></p>
           <p>Classe '.$classe_s.'<br><span style="font-size:15pt">'.$corso_s.'</span></p>
           <p><i>'.$materia_s.'</i></p>';
    $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    // reset carattere
    $this->pdf->getHandler()->SetFont('helvetica', '', 10);
  }

  /**
   * Scrive le lezioni del docente, con argomenti e voti e osservazioni
   *
   * @param Docente $docente Docente di cui creare il registro
   * @param Cattedra $cattedra Cattedra del docente
   * @param int $periodo Periodo di riferimento
   */
  public function scriveRegistroSostegno(Docente $docente, Cattedra $cattedra, $periodo) {
    // inizializza dati
    $docente_s = $docente->getNome().' '.$docente->getCognome();
    $docente_sesso = $docente->getSesso();
    $classe_s = $cattedra->getClasse()->getAnno().'ª '.$cattedra->getClasse()->getSezione();
    $corso_s = $cattedra->getClasse()->getCorso()->getNome().' - Sede di '.$cattedra->getClasse()->getSede()->getCitta();
    $alunno_s = $cattedra->getAlunno()->getCognome().' '.$cattedra->getAlunno()->getNome().
      ' ('.$cattedra->getAlunno()->getDataNascita()->format('d/m/Y').')';
    $dati_periodi = $this->regUtil->infoPeriodi();
    $periodo_s = $dati_periodi[$periodo]['nome'];
    $annoscolastico = $this->session->get('/CONFIG/SCUOLA/anno_scolastico').
      ' - '.$periodo_s;
    $nomemesi = array('', 'GEN','FEB','MAR','APR','MAG','GIU','LUG','AGO','SET','OTT','NOV','DIC');
    $nomesett = array('Dom','Lun','Mar','Mer','Gio','Ven','Sab');
    // suddivide per materia
    $materie = $this->em->getRepository('App:Cattedra')->createQueryBuilder('c')
      ->select('DISTINCT m.id,m.nome')
      ->join('c.materia', 'm')
      ->where('c.classe=:classe')
      ->orderBy('m.ordinamento', 'ASC')
      ->setParameters(['classe' => $cattedra->getClasse()])
      ->getQuery()
      ->getArrayResult();
    foreach ($materie as $mat) {
      $dati['lezioni'] = array();
      $dati['argomenti'] = array();
      $dati['osservazioni'] = array();
      $dati['personali'] = array();
      $dati['assenze'] = 0;
      $materia_s = $mat['nome'];
      // ore totali
      $ore = $this->em->getRepository('App:Lezione')->createQueryBuilder('l')
        ->select('SUM(so.durata)')
        ->join('App:FirmaSostegno', 'fs', 'WITH', 'l.id=fs.lezione AND fs.docente=:docente AND fs.alunno=:alunno')
        ->join('App:ScansioneOraria', 'so', 'WITH', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
        ->join('so.orario', 'o')
        ->where('l.classe=:classe AND l.materia=:materia AND l.data BETWEEN :inizio AND :fine AND l.data BETWEEN o.inizio AND o.fine AND o.sede=:sede')
        ->setParameters(['docente' => $docente, 'alunno' => $cattedra->getAlunno(),
          'classe' => $cattedra->getClasse(), 'materia' => $mat['id'],
          'inizio' => $dati_periodi[$periodo]['inizio'], 'fine' => $dati_periodi[$periodo]['fine'],
          'sede' => $cattedra->getClasse()->getSede()])
        ->getQuery()
        ->getSingleScalarResult();
      $ore = rtrim(rtrim(number_format($ore, 1, ',', ''), '0'), ',');
      if ($ore > 0) {
        // legge lezioni del periodo
        $lezioni = $this->em->getRepository('App:Lezione')->createQueryBuilder('l')
          ->select('l.id,l.data,l.ora,so.durata,l.argomento,l.attivita,fs.argomento AS argomento_sos,fs.attivita AS attivita_sos')
          ->join('App:FirmaSostegno', 'fs', 'WITH', 'l.id=fs.lezione AND fs.docente=:docente AND fs.alunno=:alunno')
          ->join('App:ScansioneOraria', 'so', 'WITH', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
          ->join('so.orario', 'o')
          ->where('l.classe=:classe AND l.materia=:materia AND l.data BETWEEN :inizio AND :fine AND l.data BETWEEN o.inizio AND o.fine AND o.sede=:sede')
          ->orderBy('l.data,l.ora', 'ASC')
          ->setParameters(['docente' => $docente, 'alunno' => $cattedra->getAlunno(),
            'classe' => $cattedra->getClasse(), 'materia' => $mat['id'],
            'inizio' => $dati_periodi[$periodo]['inizio'], 'fine' => $dati_periodi[$periodo]['fine'],
            'sede' => $cattedra->getClasse()->getSede()])
          ->getQuery()
          ->getArrayResult();
        // legge assenze
        $data_prec = null;
        $giornilezione = array();
        foreach ($lezioni as $l) {
          if (!$data_prec || $l['data'] != $data_prec) {
            // cambio di data
            $giornilezione[] = $l['data'];
            $mese = (int) $l['data']->format('m');
            $giorno = (int) $l['data']->format('d');
            $dati['lezioni'][$mese][$giorno]['durata'] = 0;
            // controlla se alunno in classe per data
            $lista = $this->regUtil->alunniInData($l['data'], $cattedra->getClasse());
            if (in_array($cattedra->getAlunno()->getId(), $lista)) {
              $dati['lezioni'][$mese][$giorno]['classe'] = 1;
            }
          }
          // aggiorna durata lezioni
          $dati['lezioni'][$mese][$giorno]['durata'] += $l['durata'];
          // legge assenze
          $assenze = $this->em->getRepository('App:AssenzaLezione')->createQueryBuilder('al')
            ->select('SUM(al.ore)')
            ->where('al.lezione=:lezione AND al.alunno=:alunno')
            ->setParameters(['lezione' => $l['id'], 'alunno' => $cattedra->getAlunno()])
            ->getQuery()
            ->getSingleScalarResult();
          // somma ore di assenza per alunno
          if ($assenze > 0) {
            if (isset($dati['lezioni'][$mese][$giorno]['assenze'])) {
              $dati['lezioni'][$mese][$giorno]['assenze'] += $assenze;
            } else {
              $dati['lezioni'][$mese][$giorno]['assenze'] = $assenze;
            }
          }
          // memorizza data precedente
          $data_prec = $l['data'];
        }
        // imposta lezioni per pagina
        $numerotbl_lezioni = count($giornilezione);
        $lezperpag = 20;
        $colfinali = 2; // solo assenze
        $colresidue = ($numerotbl_lezioni + $colfinali) % $lezperpag;
        $numeropagine = (int)(($numerotbl_lezioni + $colfinali) / $lezperpag);
        if ($colresidue > 0) {
          $numeropagine++;
        }
        // cicla per ogni pagina
        for ($np = 0; $np < $numeropagine; $np++) {
          if ($np == 0) {
            // prima pagina
            $this->intestazionePagina('Lezioni della classe', $docente_s, $classe_s, $corso_s, $materia_s, $annoscolastico);
            $html = '<br>Totale ore di lezione: '.$ore;
            $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
            $numero_tabelle = 1;
          } elseif ($numero_tabelle == 5) {
            // cambio pagina
            $this->intestazionePagina('Lezioni della classe', $docente_s, $classe_s, $corso_s, $materia_s, $annoscolastico);
            $numero_tabelle = 1;
          } else {
            // nuova tabella nella stessa pagina
            $numero_tabelle++;
            $html = '<br><br>';
            $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
          }
          // intestazione tabella
          $html_col = '';
          $html_inizio = '<table border="1"><tr><td style="width:75mm"><b>Alunno</b></td>';
          $html_inizio_rs = '<table border="1"><tr><td style="width:75mm" rowspan="2"><b>Alunno</b></td>';
          $html = '';
          for ($ng = $np * $lezperpag; $ng < min(($np + 1) * $lezperpag, $numerotbl_lezioni); $ng++) {
            $g = $giornilezione[$ng];
            $gs = $nomesett[$g->format('w')];
            $gg = $g->format('j');
            $gm = $nomemesi[$g->format('n')];
            $strore = rtrim(rtrim(number_format($dati['lezioni'][$g->format('n')][$g->format('j')]['durata'], 1, ',', ''), '0'), ',');
            $html .= '<td style="width:10mm"><b>'.$gs.'<br>'.$gg.'<br>'.$gm.'</b></td>';
            $html_col .= '<td><i>'.$strore.'</i></td>';
          }
          if ($np == $numeropagine - 1) {
            $rspan = ($html_col == '' ? '' : ' rowspan="2"');
            $html .= '<td style="width:20mm"'.$rspan.'><b>Totale<br>ore di<br>assenza</b></td>';
          }
          $html = ($html_col == '' ? $html_inizio : $html_inizio_rs).$html.'</tr>'.
            ($html_col == '' ? '' : '<tr>'.$html_col.'</tr>');
          // dati alunno
          $html .= '<tr nobr="true" style="font-size:9pt">'.
            '<td align="left"> '.
            ((!$cattedra->getAlunno()->getClasse() || $cattedra->getAlunno()->getClasse()->getId() != $cattedra->getClasse()->getId()) ? '* ' : '').
            $alunno_s.'</td>';
          // assenze
          for ($ng = $np * $lezperpag; $ng < min(($np + 1) * $lezperpag, $numerotbl_lezioni); $ng++) {
            $g = $giornilezione[$ng];
            $gg = $g->format('j');
            $gm = $g->format('n');
            if (isset($dati['lezioni'][$gm][$gg]['classe'])) {
              // alunno inserito in classe
              $html .= '<td>';
              // assenze
              if (isset($dati['lezioni'][$gm][$gg]['assenze'])) {
                $ass = $dati['lezioni'][$gm][$gg]['assenze'];
                $html .= str_repeat('A', intval($ass)).(($ass - intval($ass)) > 0 ? 'a' : '');
                $dati['assenze'] += $ass;
              }
              $html .= '</td>';
            } else {
              // alunno non inserito in classe
              $html .= '<td style="background-color:#CCCCCC">&nbsp;</td>';
            }
          }
          if ($np == $numeropagine - 1) {
            // tot. assenze
            $html .= '<td>'.rtrim(rtrim(number_format($dati['assenze'], 1, ',', ''), '0'), ',').'</td>';
          }
          $html .= '</tr>';
          // fine tabella
          $html .= '</table>';
          $this->pdf->getHandler()->writeHTML($html, false, false, false, false, 'C');
          if ($np == $numeropagine -1) {
            // ultima pagina
            $html = '<b>A</b> = assenza di un\'ora; <b>a</b> = assenza di mezzora';
            if (!$cattedra->getAlunno()->getClasse() || $cattedra->getAlunno()->getClasse()->getId() != $cattedra->getClasse()->getId()) {
              $html .= '<br><b>*</b> Alunno ritirato/trasferito/frequenta l\'anno all\'estero';
            }
            $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
          }
        }
        // legge argomenti e attività
        $data_prec = null;
        $num_mat = 0;
        $num_sos = 0;
        foreach ($lezioni as $l) {
          $data = $l['data']->format('d/m/Y');
          if ($data_prec && $data != $data_prec) {
            if ($num_mat == 0) {
              // nessun argomento/attività della materia in data precedente
              $dati['argomenti'][$data_prec]['materia'][0] = '';
            }
            if ($num_sos == 0) {
              // nessuna argomento/attività di sostegno in data precedente
              $dati['argomenti'][$data_prec]['sostegno'][0] = '';
            }
            // fa ripartire contatori
            $num_mat = 0;
            $num_sos = 0;
          }
          // materia
          $testo1 = $this->ripulisceTesto($l['argomento']);
          $testo2 = $this->ripulisceTesto($l['attivita']);
          $testo = $testo1.(($testo1 != '' && $testo2 != '') ? ' - ' : '').$testo2;
          if ($testo != '' && ($num_mat == 0 ||
              strcasecmp($testo, $dati['argomenti'][$data]['materia'][$num_mat - 1]) != 0)) {
            // evita ripetizioni identiche degli argomenti
            $dati['argomenti'][$data]['materia'][$num_mat] = $testo;
            $num_mat++;
          }
          // sostegno
          $testo1 = $this->ripulisceTesto($l['argomento_sos']);
          $testo2 = $this->ripulisceTesto($l['attivita_sos']);
          $testo = $testo1.(($testo1 != '' && $testo2 != '') ? ' - ' : '').$testo2;
          if ($testo != '' && ($num_sos == 0 ||
              strcasecmp($testo, $dati['argomenti'][$data]['sostegno'][$num_sos - 1]) != 0)) {
            // evita ripetizioni identiche degli argomenti
            $dati['argomenti'][$data]['sostegno'][$num_sos] = $testo;
            $num_sos++;
          }
          // memorizza data attuale
          $data_prec = $data;
        }
        if ($data_prec && $num_mat == 0) {
          // nessun argomento/attività della materia in data precedente
          $dati['argomenti'][$data_prec]['materia'][0] = '';
        }
        if ($data_prec && $num_sos == 0) {
          // nessuna argomento/attività di sostegno in data precedente
          $dati['argomenti'][$data_prec]['sostegno'][0] = '';
        }
        // scrive argomenti e attività
        $this->intestazionePagina('Argomenti e attivit&agrave; della classe', $docente_s, $classe_s, $corso_s, $materia_s, $annoscolastico);
        $html = '<table border="1" style="left-padding:2mm">
          <tr>
            <td style="width:10%"><b>Data</b></td>
            <td style="width:45%"><b>Argomenti/Attivit&agrave; della materia</b></td>
            <td style="width:45%"><b>Argomenti/Attivit&agrave; di sostegno</b></td>
          </tr>';
        foreach ($dati['argomenti'] as $d=>$arg) {
          $html .= '<tr nobr="true"><td>'.$d.'</td>'.
              '<td align="left">'.implode('<br>', $arg['materia']).'</td>'.
              '<td align="left">'.implode('<br>', $arg['sostegno']).'</td>'.
            '</tr>';
        }
        $html .= '</table>';
        $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
      }
    }
    // legge osservazioni sugli alunni
    $osservazioni = $this->em->getRepository('App:OsservazioneAlunno')->createQueryBuilder('o')
      ->select('o.data,o.testo,a.id AS alunno_id,a.cognome,a.nome,a.dataNascita')
      ->join('o.alunno', 'a')
      ->where('o.cattedra=:cattedra AND o.data BETWEEN :inizio AND :fine')
      ->orderBy('o.data,a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['cattedra' => $cattedra, 'inizio' => $dati_periodi[$periodo]['inizio'],
        'fine' => $dati_periodi[$periodo]['fine']])
      ->getQuery()
      ->getArrayResult();
    foreach ($osservazioni as $o) {
      $dati['osservazioni'][$o['data']->format('d/m/Y')][$o['alunno_id']][] = $o;
    }
    // scrive osservazioni sugli alunni
    if (count($osservazioni) > 0) {
      $this->intestazionePagina('Osservazioni sugli alunni della classe', $docente_s, $classe_s, $corso_s, 'Sostegno', $annoscolastico);
      // tabella osservazioni
      $html = '<table border="1" style="left-padding:2mm">
        <tr>
          <td style="width:10%"><b>Data</b></td>
          <td style="width:30%"><b>Alunno</b></td>
          <td style="width:60%"><b>Osservazioni</b></td>
        </tr>';
      foreach ($dati['osservazioni'] as $dt=>$oa) {
        foreach ($oa as $oo) {
          foreach ($oo as $oss) {
            $html .= '<tr nobr="true">'.
                '<td>'.$dt.'</td>'.
                '<td style="text-align:left">'.$oss['cognome'].' '.$oss['nome'].' ('.$oss['dataNascita']->format('d/m/Y').')'.'</td>'.
                '<td style="font-size:9pt;text-align:left">'.$this->ripulisceTesto($oss['testo']).'</td>'.
              '</tr>';
          }
        }
      }
      $html .= '</table>';
      $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    }
    // legge osservazioni personali
    $personali = $this->em->getRepository('App:OsservazioneClasse')->createQueryBuilder('o')
      ->select('o.data,o.testo')
      ->where('NOT (o INSTANCE OF App:OsservazioneAlunno) AND o.cattedra=:cattedra AND o.data BETWEEN :inizio AND :fine')
      ->orderBy('o.data', 'ASC')
      ->setParameters(['cattedra' => $cattedra, 'inizio' => $dati_periodi[$periodo]['inizio'],
        'fine' => $dati_periodi[$periodo]['fine']])
      ->getQuery()
      ->getArrayResult();
    foreach ($personali as $p) {
      $dati['personali'][$p['data']->format('d/m/Y')][] = $p;
    }
    // scrive osservazioni personali
    if (count($personali) > 0) {
      $this->intestazionePagina('Osservazioni sulla classe', $docente_s, $classe_s, $corso_s, 'Sostegno', $annoscolastico);
      // tabella osservazioni
      $html = '<table border="1" style="left-padding:2mm">
        <tr>
          <td style="width:10%"><b>Data</b></td>
          <td style="width:90%"><b>Osservazioni</b></td>
        </tr>';
      foreach ($dati['personali'] as $dt=>$o) {
        foreach ($o as $osp) {
          $html .= '<tr nobr="true">'.
              '<td>'.$dt.'</td>'.
              '<td style="font-size:9pt;text-align:left">'.$this->ripulisceTesto($osp['testo']).'</td>'.
            '</tr>';
        }
      }
      $html .= '</table>';
      $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    }
  }

  /**
   * Crea la pagina iniziale del registro di classe
   *
   * @param Classe $classe Classe di cui creare il registro
   * @param int $periodo Periodo di riferimento
   */
  public function copertinaRegistroClasse(Classe $classe, $periodo) {
    // nuova pagina
    $this->pdf->getHandler()->AddPage('L');
    // crea copertina
    $this->pdf->getHandler()->SetFont('times', '', 14);
    $html = '
      <div style="text-align:center">
        <img src="/img/'.$this->localpath.'intestazione-documenti.jpg" width="600">
      </div>';
    $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    $this->pdf->getHandler()->SetFont('helvetica', 'B', 18);
    $dati_periodi = $this->regUtil->infoPeriodi();
    $periodo_s = $dati_periodi[$periodo]['nome'];
    $annoscolastico = $this->session->get('/CONFIG/SCUOLA/anno_scolastico').
      ' - '.$periodo_s;
    $classe_s = $classe->getAnno().'ª '.$classe->getSezione();
    $corso_s = $classe->getCorso()->getNome();
    $sede_s = 'Sede di '.$classe->getSede()->getCitta();
    $html = '<br>
      <p>A.S. '.$annoscolastico.'</p>
      <p style="font-size:20pt">Registro di classe</p>
      <p style="font-size:20pt">'.$classe_s.'<br>'.$corso_s.'<br>'.$sede_s.'</p>';
    $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
    // reset carattere
    $this->pdf->getHandler()->SetFont('helvetica', '', 10);
  }

  /**
   * Scrive il registro di classe
   *
   * @param Classe $classe Classe di cui creare il registro
   * @param int $periodo Periodo di riferimento
   */
  public function scriveRegistroClasse(Classe $classe, $periodo) {
    // inizializza dati
    $dati_periodi = $this->regUtil->infoPeriodi();
    $periodo_s = $dati_periodi[$periodo]['nome'];
    $annoscolastico = $this->session->get('/CONFIG/SCUOLA/anno_scolastico').
      ' - '.$periodo_s;
    $classe_s = $classe->getAnno().'ª '.$classe->getSezione();
    $corso_s = $classe->getCorso()->getNome().' - Sede di '.$classe->getSede()->getCitta();
    $nomemesi = array('','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre');
    $nomesett = array('Domenica','Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato');
    // festivi
    $festivi = $this->em->getRepository('App:Festivita')->createQueryBuilder('f')
      ->select('f.data')
      ->where('f.tipo=:festivo AND (f.sede IS NULL OR f.sede=:sede)')
      ->orderBy('f.data', 'ASC')
      ->setParameters(['festivo' => 'F', 'sede' => $classe->getSede()])
      ->getQuery()
      ->getArrayResult();
    $giorni_festivi = array();
    foreach ($festivi as $f) {
      $giorni_festivi[] = $f['data']->format('Y-m-d');
    }
    // elenco giorni
    $data = \DateTime::createFromFormat('Y-m-d H:i', $dati_periodi[$periodo]['inizio'].' 00:00');
    $data_fine = \DateTime::createFromFormat('Y-m-d H:i', $dati_periodi[$periodo]['fine'].' 00:00');
    for ( ; $data <= $data_fine; $data->modify('+1 day')) {
      $dati['lezioni'] = array();
      $dati['note'] = array();
      $dati['annotazioni'] = array();
      $dati['assenze'] = array();
      $dati['ritardi'] = array();
      $dati['uscite'] = array();
      $dati['giustificazioni'] = array();
      // controlla festivo
      if ($data->format('w') == 0 || in_array($data->format('Y-m-d'), $giorni_festivi)) {
        // domenica o festivo
        continue;
      }
      // intestazione pagina
      $this->intestazionePagina('Registro della classe', null, $classe_s, $corso_s, null, $annoscolastico);
      $html = '<div style="font-size:14pt"><b>'.
        $nomesett[$data->format('w')].' '.$data->format('j').' '.$nomemesi[$data->format('n')].' '.$data->format('Y').
        '</b></div><br>';
      $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
      // legge orario e lezioni
      $scansioneoraria = $this->regUtil->orarioInData($data, $classe->getSede());
      foreach ($scansioneoraria as $so) {
        $ora = $so['ora'];
        $dati['lezioni'][$ora]['inizio'] = substr($so['inizio'], 0, 5);
        $dati['lezioni'][$ora]['fine'] = substr($so['fine'], 0, 5);
        // legge lezione
        $lezione = $this->em->getRepository('App:Lezione')->createQueryBuilder('l')
          ->where('l.data=:data AND l.classe=:classe AND l.ora=:ora')
          ->setParameters(['data' => $data->format('Y-m-d'), 'classe' => $classe, 'ora' => $ora])
          ->getQuery()
          ->getOneOrNullResult();
        if ($lezione) {
          // esiste lezione
          $dati['lezioni'][$ora]['materia'] = $lezione->getMateria()->getNome();
          $testo1 = $this->ripulisceTesto($lezione->getArgomento());
          $testo2 = $this->ripulisceTesto($lezione->getAttivita());
          $dati['lezioni'][$ora]['argomenti'] = $testo1.(($testo1 && $testo2) ? ' - ' : '').$testo2;
          // legge firme
          $firme = $this->em->getRepository('App:Firma')->createQueryBuilder('f')
            ->join('f.docente', 'd')
            ->where('f.lezione=:lezione')
            ->orderBy('d.cognome,d.nome', 'ASC')
            ->setParameters(['lezione' => $lezione])
            ->getQuery()
            ->getResult();
          foreach ($firme as $f) {
            $dati['lezioni'][$ora]['docenti'][] = $f->getDocente()->getNome().' '.$f->getDocente()->getCognome();
          }
        } else {
          // nessuna lezione esistente
          $dati['lezioni'][$ora]['materia'] = '';
          $dati['lezioni'][$ora]['argomenti'] = '';
          $dati['lezioni'][$ora]['docenti'] = [];
        }
      }
      // scrive tabella lezioni
      $html = '<table border="1" style="left-padding:2mm">
        <tr>
          <td style="width: 4%"><b>Ora</b></td>
          <td style="width:26%"><b>Materia</b></td>
          <td style="width:20%"><b>Docenti</b></td>
          <td style="width:50%"><b>Argomenti/Attività</b></td>
        </tr>';
      foreach ($dati['lezioni'] as $lez) {
        $html .= '<tr nobr="true">'.
            '<td>'.$lez['inizio'].'<br> - <br>'.$lez['fine'].'</td>'.
            '<td align="left"><b>'.$lez['materia'].'</b></td>'.
            '<td align="left"><i>'.implode('<br>', $lez['docenti']).'</i></td>'.
            '<td align="left" style="font-size:9pt">'.$lez['argomenti'].'</td>'.
          '</tr>';
      }
      // chiude tabella lezioni
      $html .= '</table>';
      $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
      // legge alunni
      $lista = $this->regUtil->alunniInData($data, $classe);
      // legge giustificazioni assenze
      $giustificaAssenze = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.cognome,a.nome,a.dataNascita,ass.data')
        ->join('App:Assenza', 'ass', 'WITH', 'a.id=ass.alunno AND ass.giustificato=:data')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita,ass.data', 'ASC')
        ->setParameters(['lista' => $lista, 'data' => $data->format('Y-m-d')])
        ->getQuery()
        ->getArrayResult();
      foreach ($giustificaAssenze as $ass) {
        $dati['giustificazioni'][$ass['id']]['alunno'] =
          $ass['cognome'].' '.$ass['nome'].' ('.$ass['dataNascita']->format('d/m/Y').')';
        $dati['giustificazioni'][$ass['id']]['assenza'][] = $ass['data']->format('d/m/Y');
      }
      $giustificaRitardi = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.cognome,a.nome,a.dataNascita,e.data')
        ->join('App:Entrata', 'e', 'WITH', 'a.id=e.alunno AND e.giustificato=:data')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita,e.data', 'ASC')
        ->setParameters(['lista' => $lista, 'data' => $data->format('Y-m-d')])
        ->getQuery()
        ->getArrayResult();
      foreach ($giustificaRitardi as $rit) {
        $dati['giustificazioni'][$rit['id']]['alunno'] =
          $rit['cognome'].' '.$rit['nome'].' ('.$rit['dataNascita']->format('d/m/Y').')';
        $dati['giustificazioni'][$rit['id']]['ritardo'][] = $rit['data']->format('d/m/Y');
      }
      // gestione assenze a seconda della modalità impostata
      if ($this->em->getRepository('App:Configurazione')->getParametro('assenze_ore')) {
        // assenze in modalità oraria
        $assenze = $this->em->getRepository('App:AssenzaLezione')->createQueryBuilder('al')
          ->select('a.id,a.cognome,a.nome,a.dataNascita,l.ora')
          ->join('al.alunno', 'a')
          ->join('al.lezione', 'l')
          ->where('a.id IN (:lista) AND l.data=:data')
          ->orderBy('a.cognome,a.nome,a.dataNascita,l.ora', 'ASC')
          ->setParameters(['lista' => $lista, 'data' => $data->format('Y-m-d')])
          ->getQuery()
          ->getArrayResult();
        foreach ($assenze as $ass) {
          $dati['assenze'][$ass['id']]['alunno'] =
            $ass['cognome'].' '.$ass['nome'].' ('.$ass['dataNascita']->format('d/m/Y').')';
          $dati['assenze'][$ass['id']]['ore'][] = $ass['ora'].'ª';
        }
        // scrive assenze/giustificazioni
        $html = '<br><table border="1" cellspacing="0" cellpadding="4" nobr="true">
          <tr>
            <td style="width:50%"><b>Ore di assenza</b></td>
            <td style="width:50%"><b>Giustificazioni</b></td>
          </tr>';
        // assenze
        $html .= '<tr><td align="left" style="font-size:9pt">';
        $primo = true;
        foreach ($dati['assenze'] as $ass) {
          $html .= (!$primo ? '<br>- ' : '- ').$ass['alunno'].': '.
            implode(', ', $ass['ore']).'.';
          $primo = false;
        }
        // giustificazioni
        $html .= '</td><td align="left" style="font-size:9pt">';
        $primo = true;
        foreach ($dati['giustificazioni'] as $alu=>$giu) {
          $html .= (!$primo ? '<br>- ' : '- ').$giu['alunno'].': ';
          $primo = false;
          if (!empty($giu['assenza'])) {
            $html .= 'Assenz'.(count($giu['assenza']) > 1 ? 'e' : 'a').' del '.
              implode(', ', $giu['assenza']).'.';
          }
        }
        // chiude tabella assenze
        $html .= '</td></tr></table>';
        $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
      } else {
        // assenze in modalità giornaliera
        $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
          ->select('a.id AS id_alunno,a.cognome,a.nome,a.dataNascita,ass.id AS id_assenza,e.id AS id_entrata,e.ora AS ora_entrata,u.id AS id_uscita,u.ora AS ora_uscita')
          ->leftJoin('App:Assenza', 'ass', 'WITH', 'a.id=ass.alunno AND ass.data=:data')
          ->leftJoin('App:Entrata', 'e', 'WITH', 'a.id=e.alunno AND e.data=:data')
          ->leftJoin('App:Uscita', 'u', 'WITH', 'a.id=u.alunno AND u.data=:data')
          ->where('a.id IN (:lista)')
          ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
          ->setParameters(['lista' => $lista, 'data' => $data->format('Y-m-d')])
          ->getQuery()
          ->getArrayResult();
        foreach ($alunni as $alu) {
          if ($alu['id_assenza']) {
            $dati['assenze'][$alu['id_alunno']]['alunno'] =
              $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          }
          if ($alu['id_entrata']) {
            $dati['ritardi'][$alu['id_alunno']]['alunno'] =
              $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
            $dati['ritardi'][$alu['id_alunno']]['ora'] = $alu['ora_entrata'];
          }
          if ($alu['id_uscita']) {
            $dati['uscite'][$alu['id_alunno']]['alunno'] =
              $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
            $dati['uscite'][$alu['id_alunno']]['ora'] = $alu['ora_uscita'];
          }
        }
        // scrive assenze/giustificazioni
        $html = '<br><table border="1" cellspacing="0" cellpadding="4" nobr="true">
          <tr>
            <td style="width:25%"><b>Assenze</b></td>
            <td style="width:25%"><b>Ritardi</b></td>
            <td style="width:25%"><b>Uscite anticipate</b></td>
            <td style="width:25%"><b>Giustificazioni</b></td>
          </tr>';
        // assenze
        $html .= '<tr><td align="left" style="font-size:9pt">';
        $primo = true;
        foreach ($dati['assenze'] as $ass) {
          $html .= (!$primo ? '<br>- ' : '- ').$ass['alunno'];
          $primo = false;
        }
        // ritardi
        $html .= '</td><td align="left" style="font-size:9pt">';
        $primo = true;
        foreach ($dati['ritardi'] as $rit) {
          $html .= (!$primo ? '<br>' : '').'- <b>'.$rit['ora']->format('H:i').'</b> - '.$rit['alunno'];
          $primo = false;
        }
        // uscite
        $html .= '</td><td align="left" style="font-size:9pt">';
        $primo = true;
        foreach ($dati['uscite'] as $usc) {
          $html .= (!$primo ? '<br>' : '').'- <b>'.$usc['ora']->format('H:i').'</b> - '.$usc['alunno'];
          $primo = false;
        }
        // giustificazioni
        $html .= '</td><td align="left" style="font-size:9pt">';
        $primo = true;
        foreach ($dati['giustificazioni'] as $alu=>$giu) {
          $html .= (!$primo ? '<br>- ' : '- ').$giu['alunno'].': ';
          $primo = false;
          if (!empty($giu['assenza'])) {
            $html .= 'Assenz'.(count($giu['assenza']) > 1 ? 'e' : 'a').' del '.
              implode(', ', $giu['assenza']).'.';
          }
          if (!empty($giu['ritardo'])) {
            $html .= (!empty($giu['assenza']) ? '<br>' : '').
              'Ritard'.(count($giu['ritardo']) > 1 ? 'i' : 'o').' del '.
              implode(', ', $giu['ritardo']).'.';
          }
        }
        // chiude tabella assenze
        $html .= '</td></tr></table>';
        $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
      }
      // legge note
      $note = $this->em->getRepository('App:Nota')->createQueryBuilder('n')
        ->join('n.docente', 'd')
        ->leftJoin('n.docenteProvvedimento', 'dp')
        ->where('n.data=:data AND n.classe=:classe')
        ->orderBy('n.modificato', 'ASC')
        ->setParameters(['data' => $data->format('Y-m-d'), 'classe' => $classe])
        ->getQuery()
        ->getResult();
      foreach ($note as $n) {
        $alunni = array();
        foreach ($n->getAlunni() as $alu) {
          $alunni[] = $alu->getCognome().' '.$alu->getNome();
        }
        $dati['note'][] = array(
          'tipo' => $n->getTipo(),
          'testo' => $this->ripulisceTesto($n->getTesto()),
          'provvedimento' => $this->ripulisceTesto($n->getProvvedimento()),
          'docente' => $n->getDocente()->getNome().' '.$n->getDocente()->getCognome(),
          'docente_provvedimento' => ($n->getDocenteProvvedimento() ?
            $n->getDocenteProvvedimento()->getNome().' '.$n->getDocenteProvvedimento()->getCognome() : null),
          'alunni' => $alunni);
      }
      // legge annotazioni
      $annotazioni = $this->em->getRepository('App:Annotazione')->createQueryBuilder('a')
        ->join('a.docente', 'd')
        ->where('a.data=:data AND a.classe=:classe')
        ->orderBy('a.modificato', 'ASC')
        ->setParameters(['data' => $data->format('Y-m-d'), 'classe' => $classe])
        ->getQuery()
        ->getResult();
      foreach ($annotazioni as $a) {
        $alunni = array();
        if ($a->getAvviso() && $a->getVisibile()) {
          // legge alunni destinatari
          $ann_alunni = $this->em->getRepository('App:AvvisoUtente')->createQueryBuilder('au')
            ->join('au.utente', 'u')
            ->where('au.avviso=:avviso AND (u INSTANCE OF App:Genitore)')
            ->setParameters(['avviso' => $a->getAvviso()])
            ->orderBy('u.cognome,u.nome', 'ASC')
            ->getQuery()
            ->getResult();
          foreach ($ann_alunni as $ann) {
            $alunni[] = $ann->getUtente()->getCognome().' '.$ann->getUtente()->getNome();
          }
        }
        $dati['annotazioni'][] = array(
          'testo' => $this->ripulisceTesto($a->getTesto()),
          'docente' => $a->getDocente()->getNome().' '.$a->getDocente()->getCognome(),
          'alunni' => $alunni);
      }
      // scrive tabella note/annotazioni
      if (count($dati['note']) > 0 || count($dati['annotazioni']) > 0) {
        $html = '<table border="1" cellspacing="0" cellpadding="0" nobr="true">
          <tr>
            <td style="width:50%"><b>Note disciplinari</b></td>
            <td style="width:50%"><b>Annotazioni</b></td>
          </tr>
          <tr>
            <td>';
        if (count($dati['note']) > 0) {
          $html .= '<table border="1" cellspacing="0" cellpadding="4">';
          foreach ($dati['note'] as $nt) {
            $html .= '<tr><td align="left">';
            if (count($nt['alunni']) > 0) {
              $html .= '<i>Alunni: <b>'.implode('</b>, <b>', $nt['alunni']).'</b></i><br>';
            }
            $html .= '<span style="font-size:9pt">'.$nt['testo'].'</span><br>'.
              '(<i>'.$nt['docente'].'</i>)';
            if ($nt['provvedimento'] != '') {
              $html .= '<br><br>Provvedimento disciplinare:<br>'.
                '<b style="font-size:9pt">'.$nt['provvedimento'].'</b><br>'.
                '(<i>'.$nt['docente_provvedimento'].'</i>)';
            }
            $html .= '</td></tr>';
          }
          $html .= '</table>';
        }
        $html .= '</td><td>';
        if (count($dati['annotazioni']) > 0) {
          $html .= '<table border="1" cellspacing="0" cellpadding="4">';
          foreach ($dati['annotazioni'] as $an) {
            $html .= '<tr><td align="left">';
            if (count($an['alunni']) > 0) {
              $html .= '<i>Destinatari (genitori): <b>'.implode('</b>, <b>', $an['alunni']).'</b></i><br>';
            }
            $html .= '<span style="font-size:9pt">'.$an['testo'].'</span><br>'.
              '(<i>'.$an['docente'].'</i>)';
            $html .= '</td></tr>';
          }
          $html .= '</table>';
        }
        // chiude tabella note/annotazioni
        $html .= '</td></tr></table>';
        $this->pdf->getHandler()->writeHTML($html, true, false, false, false, 'C');
      }
    }
  }

  /**
   * Crea i documenti degli scrutini per tutte le classe
   *
   * @param Array $classi Lista delle classi di cui creare i documenti degli scrutini
   */
  public function tuttiScrutiniClasse($classi) {
    foreach ($classi as $cl) {
      $this->scrutinioClasse($cl);
    }
  }

  /**
   * Crea i documenti degli scrutini per la classe
   *
   * @param Classe $classe Classe di cui creare i documenti degli scrutini
   */
  public function scrutinioClasse(Classe $classe) {
    $msg = array();
    // legge gli scrutini della classe
    $scrutini = $this->em->getRepository('App:Scrutinio')->findBy(['classe' => $classe, 'stato' => 'C'],
      ['data' => 'ASC']);
    foreach ($scrutini as $scrut) {
      $adesso = (new \DateTime())->format('Y-m-d H:i');
      $periodo = $scrut->getPeriodo();
      switch ($periodo) {
        case 'P': // scrutinio primo periodo
          // riepilogo voti
          if (!($file = $this->pag->riepilogoVoti($classe, $periodo))) {
            // errore
            $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Riepilogo: '.
              'non creato per mancanza di dati.';
          } else {
            $data_file = (new \DateTime('@'.filemtime($file)))
              ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
            $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Riepilogo'.
              ($data_file >= $adesso ? ' (NUOVO)': '');
          }
          // verbale
          if (!($file = $this->pag->verbale($classe, $periodo))) {
            // errore
            $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Verbale: '.
              'non creato per mancanza di dati.';
          } else {
            $data_file = (new \DateTime('@'.filemtime($file)))
              ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
            $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Verbale'.
              ($data_file >= $adesso ? ' (NUOVO)': '');
          }
          // debiti
          $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
            ->join('App:VotoScrutinio', 'vs', 'WITH', 'vs.alunno=a.id AND vs.scrutinio=:scrutinio')
            ->join('vs.materia', 'm')
            ->where('a.id IN (:lista) AND vs.unico IS NOT NULL AND vs.unico<:suff AND m.tipo IN (:tipi)')
            ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
            ->setParameters(['scrutinio' => $scrut, 'lista' => $scrut->getDato('alunni'), 'suff' => 6,
              'tipi' => ['N', 'E']])
            ->getQuery()
            ->getResult();
          $debiti_num = 0;
          $debiti_nuovi = 0;
          foreach ($alunni as $alu) {
            // comunicazione debiti
            if (!($file = $this->pag->debiti($classe, $alu, $periodo))) {
              // errore
              $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Debiti '.
                $alu->getCognome().' '.$alu->getNome().' ('.$alu->getDataNascita()->format('d/m/Y').') : '.
                'non creato per mancanza di dati.';
            } else {
              $debiti_num++;
              $data_file = (new \DateTime('@'.filemtime($file)))
                ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
              if ($data_file >= $adesso) {
                $debiti_nuovi++;
              }
            }
          }
          $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Debiti: '.
            $debiti_num.' ('.$debiti_nuovi.' NUOVI)';
          break;
        case 'F': // scrutinio finale
          // riepilogo voti
          if (!($file = $this->pag->riepilogoVoti($classe, $periodo))) {
            // errore
            $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Riepilogo: '.
              'non creato per mancanza di dati.';
          } else {
            $data_file = (new \DateTime('@'.filemtime($file)))
              ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
            $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Riepilogo'.
              ($data_file >= $adesso ? ' (NUOVO)': '');
          }
          // verbale
          if (!($file = $this->pag->verbale($classe, $periodo))) {
            // errore
            $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Verbale: '.
              'non creato per mancanza di dati.';
          } else {
            $data_file = (new \DateTime('@'.filemtime($file)))
              ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
            $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Verbale'.
              ($data_file >= $adesso ? ' (NUOVO)': '');
          }
          // certificazioni
          if ($classe->getAnno() == 2) {
            if (!($file = $this->pag->certificazioni($classe, $periodo))) {
              // errore
              $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Certificazioni: '.
                'non creato per mancanza di dati.';
            } else {
              $data_file = (new \DateTime('@'.filemtime($file)))
                ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
              $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Certificazioni'.
                ($data_file >= $adesso ? ' (NUOVO)': '');
            }
          }
          // debiti
          $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
            ->join('App:Esito', 'e', 'WITH', 'e.alunno=a.id AND e.scrutinio=:scrutinio')
            ->where('a.id IN (:lista) AND e.esito=:sospeso')
            ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
            ->setParameters(['scrutinio' => $scrut, 'lista' => $scrut->getDato('alunni'), 'sospeso' => 'S'])
            ->getQuery()
            ->getResult();
          $debiti_num = 0;
          $debiti_nuovi = 0;
          foreach ($alunni as $alu) {
            // comunicazione debiti
            if (!($file = $this->pag->debiti($classe, $alu, $periodo))) {
              // errore
              $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Debiti '.
                $alu->getCognome().' '.$alu->getNome().' ('.$alu->getDataNascita()->format('d/m/Y').') : '.
                'non creato per mancanza di dati.';
            } else {
              $debiti_num++;
              $data_file = (new \DateTime('@'.filemtime($file)))
                ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
              if ($data_file >= $adesso) {
                $debiti_nuovi++;
              }
            }
          }
          $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Debiti: '.
            $debiti_num.' ('.$debiti_nuovi.' NUOVI)';
          // carenze
          $esiti = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
            ->join('e.alunno', 'a')
            ->where('e.scrutinio=:scrutinio AND e.esito IN (:esiti) AND a.id IN (:lista)')
            ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
            ->setParameters(['scrutinio' => $scrut, 'esiti' => ['A', 'S'], 'lista' => $scrut->getDato('alunni')])
            ->getQuery()
            ->getResult();
          $carenze_num = 0;
          $carenze_nuovi = 0;
          foreach ($esiti as $e) {
            // comunicazione carenze
            if (isset($e->getDati()['carenze']) && isset($e->getDati()['carenze_materie']) &&
                $e->getDati()['carenze'] && count($e->getDati()['carenze_materie']) > 0) {
              if (!($file = $this->pag->carenze($classe, $e->getAlunno(), $periodo))) {
                // errore
                $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Carenze '.
                  $alu->getCognome().' '.$alu->getNome().' ('.$alu->getDataNascita()->format('d/m/Y').') : '.
                  'non creato per mancanza di dati.';
              } else {
                $carenze_num++;
                $data_file = (new \DateTime('@'.filemtime($file)))
                  ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
                if ($data_file >= $adesso) {
                  $carenze_nuovi++;
                }
              }
            }
          }
          $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Carenze: '.
            $carenze_num.' ('.$carenze_nuovi.' NUOVI)';
          break;
        case 'E': // esame sospesi
        case 'X': // scrutinio rimandato
          // riepilogo voti
          if (!($file = $this->pag->riepilogoVoti($classe, $periodo))) {
            // errore
            $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Riepilogo: '.
              'non creato per mancanza di dati.';
          } else {
            $data_file = (new \DateTime('@'.filemtime($file)))
              ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
            $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Riepilogo'.
              ($data_file >= $adesso ? ' (NUOVO)': '');
          }
          // verbale
          if (!($file = $this->pag->verbale($classe, $periodo))) {
            // errore
            $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Verbale: '.
              'non creato per mancanza di dati.';
          } else {
            $data_file = (new \DateTime('@'.filemtime($file)))
              ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
            $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Verbale'.
              ($data_file >= $adesso ? ' (NUOVO)': '');
          }
          // certificazioni
          if ($classe->getAnno() == 2) {
            if (!($file = $this->pag->certificazioni($classe, $periodo))) {
              // errore
              $msg['warning'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Certificazioni: '.
                'non creato per mancanza di dati.';
            } else {
              $data_file = (new \DateTime('@'.filemtime($file)))
                ->setTimeZone(new \DateTimeZone('Europe/Rome'))->format('Y-m-d H:i');
              $msg['success'][] = $classe->getAnno().$classe->getSezione().' - Periodo '.$periodo.' - Certificazioni'.
                ($data_file >= $adesso ? ' (NUOVO)': '');
            }
          }
          break;
      }
    }
    // crea messaggi
    foreach ($msg as $c=>$m1) {
      foreach ($m1 as $m) {
        $this->session->getFlashBag()->add($c, $m);
      }
    }
  }

  /**
   * Restituisce il testo ripulito per una corretta visualizzazione
   *
   * @param string $testo Testo da ripulire
   * @return string Testo ripulito
   */
  public function ripulisceTesto($testo) {
    $txt = trim(htmlentities(strip_tags($testo)));
    $txt = str_replace('  ', ' ', str_replace(["\r", "\n"], ' ', $txt));
    return $txt;
  }

  /**
   * Crea l'archivio delle circolari
   *
   */
  public function archivioCircolari() {
    // inizializza
    $msg = array();
    $fs = new Filesystem();
    // percorso destinazione
    $percorso = $this->root.'/circolari';
    if (!$fs->exists($percorso)) {
      // crea directory
      $fs->mkdir($percorso, 0775);
    }
    // legge circolari
    $circolari = $this->em->getRepository('App:Circolare')->findBy(['pubblicata' => true],
      ['numero' => 'ASC']);
    $numCircolari = 0;
    foreach ($circolari as $circolare) {
      $errore = false;
      // copia circolare
      $file = new File($this->dirCircolari.'/'.$circolare->getDocumento());
      $nuovofile = $percorso.'/circolare-'.str_pad($circolare->getNumero(), 3, '0', STR_PAD_LEFT).
        '-del-'.$circolare->getData()->format('d-m-Y').'.'.$file->getExtension();
      $fs->copy($file->getPathname(), $nuovofile, true);
      //controllo esistenza del file
      if (!$fs->exists($file)) {
        // segnala errore
        $msg['warning'][] = 'Circolare n. '.$circolare->getNumero().' del '.$circolare->getData()->format('d-m-Y').
          ' non creata.';
        $errore = true;
      }
      // copia allegati
      foreach ($circolare->getAllegati() as $k=>$allegato) {
        $file = new File($this->dirCircolari.'/'.$allegato);
        $nuovofile = $percorso.'/circolare-'.str_pad($circolare->getNumero(), 3, '0', STR_PAD_LEFT).
          '-del-'.$circolare->getData()->format('d-m-Y').
          '-allegato-'.($k + 1).'.'.$file->getExtension();
        $fs->copy($file->getPathname(), $nuovofile, true);
        //controllo esistenza del file
        if (!$fs->exists($file)) {
          // segnala errore
          $msg['warning'][] = 'Allegato n. '.($k + 1).' della circolare n. '.$circolare->getNumero().
            ' non creato.';
          $errore = true;
        }
      }
      if (!$errore) {
        // circolare ok
        $numCircolari++;
      }
    }
    if ($numCircolari > 0) {
      // circolari create
      $msg['success'][] = 'Sono state archiviate '.$numCircolari.' circolari.';
    } else {
      // nessuna circolare archiviata
      $msg['warning'][] = 'Non è stata archiviata nessuna circolare.';
    }
    // crea messaggi
    foreach ($msg as $c=>$m1) {
      foreach ($m1 as $m) {
        $this->session->getFlashBag()->add($c, $m);
      }
    }
  }

}
