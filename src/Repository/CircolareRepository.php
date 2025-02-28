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


namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use App\Entity\Circolare;
use App\Entity\Utente;
use App\Entity\Classe;


/**
 * Circolare - repository
 */
class CircolareRepository extends EntityRepository {

  /**
   * Restituisce il numero per la prossima circolare
   *
   * @return integer Il numero per la prossima circolare
   */
  public function prossimoNumero() {
    // legge l'ultima circolare
    $query = $this->createQueryBuilder('c1')
      ->select('MAX(c1.data)')
      ->getDql();
    $numero = $this->createQueryBuilder('c')
      ->select('MAX(c.numero)')
      ->where('c.data=('.$query.')')
      ->getQuery()
      ->getSingleScalarResult();
    // restituisce prossimo numero
    return ($numero + 1);
  }

  /**
   * Restituisce vero se il numero non è già in uso, falso altrimenti.
   *
   * @param Circolare $circolare Circolare esistente o da inserire
   *
   * @return bool Vero se il numero non è già in uso, falso altrimenti
   */
  public function controllaNumero(Circolare $circolare) {
    // A.S. in corso
    $as = $this->_em->getRepository('App:Configurazione')->getParametro('anno_scolastico');
    $aass = explode('/', $as);
    $inizio = (new \DateTime('today'))
      ->setDate($aass[0], 9, 1);
    $fine = (new \DateTime('today'))
      ->setDate($aass[1], 8, 31);
    // legge la circolare in base al numero nell'A.S. in corso
    $trovato = $this->createQueryBuilder('c')
      ->where('c.numero=:numero AND c.data BETWEEN :inizio AND :fine')
      ->setParameters(['numero' => $circolare->getNumero(), 'inizio' => $inizio,
        'fine' => $fine]);
    if ($circolare->getId() > 0) {
      // circolare in modifica, esclude suo id
      $trovato
        ->andWhere('c.id!=:id')
        ->setParameter('id', $circolare->getId());
    }
    $trovato = $trovato
      ->getQuery()
      ->getOneOrNullResult();
    // restituisce vero se non esiste
    return ($trovato === null);
  }

  /**
   * Restituisce la lista delle circolari pubblicate secondo i criteri di ricerca indicati
   *
   * @param array $search Lista dei criteri di ricerca
   * @param int $page Pagina corrente
   * @param int $limit Numero di elementi per pagina
   *
   * @return Paginator Oggetto Paginator
   */
  public function pubblicate($search, $page, $limit) {
    // crea query base
    $query = $this->createQueryBuilder('c')
      ->where('c.data BETWEEN :inizio AND :fine AND c.oggetto LIKE :oggetto AND c.pubblicata=:pubblicata')
      ->orderBy('c.data', 'DESC')
      ->addOrderBy('c.numero', 'DESC')
      ->setParameters(['inizio' => $search['inizio'], 'fine' => $search['fine'], 'oggetto' => '%'.$search['oggetto'].'%',
        'pubblicata' => 1]);
    // crea lista con pagine
    return $this->paginate($query->getQuery(), $page, $limit);
  }

  /**
   * Restituisce la lista delle circolari in bozza
   *
   * @return array Lista di circolari
   */
  public function bozza() {
    // crea query base
    $circolari = $this->createQueryBuilder('c')
      ->where('c.pubblicata=:bozza')
      ->orderBy('c.data', 'DESC')
      ->addOrderBy('c.numero', 'DESC')
      ->setParameters(['bozza' => 0])
      ->getQuery()
      ->getResult();
    // restituisce lista
    return $circolari;
  }

 /**
   * Paginatore dei risultati della query
   *
   * @param Query $dql Query da mostrare
   * @param int $page Pagina corrente
   * @param int $limit Numero di elementi per pagina
   *
   * @return Paginator Oggetto Paginator
   */
  public function paginate($dql, $page, $limit) {
    $paginator = new Paginator($dql);
    $paginator->getQuery()
      ->setFirstResult($limit * ($page - 1))
      ->setMaxResults($limit);
    return $paginator;
  }

  /**
   * Controlla la presenza di circolari non lette e destinate agli alunni della classe indicata
   *
   * @param Classe $classe Classe a cui sono indirizzate le circolari
   *
   * @return int Numero di circolari da leggere
   */
  public function numeroCircolariClasse(Classe $classe) {
    // lista circolari
    $circolari = $this->createQueryBuilder('c')
      ->select('COUNT(c)')
      ->join('App:CircolareClasse', 'cc', 'WITH', 'cc.circolare=c.id AND cc.classe=:classe')
      ->where('c.pubblicata=:pubblicata AND cc.letta IS NULL')
      ->setParameters(['pubblicata' => 1, 'classe' => $classe])
      ->getQuery()
      ->getSingleScalarResult();
    // restituisce dati
    return $circolari;
  }

  /**
   * Controlla la presenza di circolari non lette e destinate all'utente
   *
   * @param Utente $utente Utente a cui sono indirizzate le circolari
   *
   * @return int Numero di circolari da leggere
   */
  public function numeroCircolariUtente(Utente $utente) {
    // lista circolari
    $circolari = $this->createQueryBuilder('c')
      ->select('COUNT(c)')
      ->join('App:CircolareUtente', 'cu', 'WITH', 'cu.circolare=c.id AND cu.utente=:utente')
      ->where('c.pubblicata=:pubblicata AND cu.letta IS NULL')
      ->setParameters(['pubblicata' => 1, 'utente' => $utente])
      ->getQuery()
      ->getSingleScalarResult();
    // restituisce dati
    return $circolari;
  }

  /**
   * Lista delle circolari non lette e destinate agli alunni della classe indicata
   *
   * @param Classe $classe Classe a cui sono indirizzate le circolari
   *
   * @return array Dati formattati come array associativo
   */
  public function circolariClasse(Classe $classe) {
    // lista circolari
    $circolari = $this->createQueryBuilder('c')
      ->join('App:CircolareClasse', 'cc', 'WITH', 'cc.circolare=c.id AND cc.classe=:classe')
      ->where('c.pubblicata=:pubblicata AND cc.letta IS NULL')
      ->setParameters(['pubblicata' => 1, 'classe' => $classe])
      ->getQuery()
      ->getResult();
    // restituisce dati
    return $circolari;
  }

  /**
   * Conferma la lettura della circolare da parte dell'utente
   *
   * @param Circolare $circolare Circolare da firmare
   * @param Utente $utente Destinatario della circolare
   *
   * @return bool Vero se inserita conferma di lettura, falso altrimenti
   */
  public function firma(Circolare $circolare, Utente $utente) {
    // dati destinatario
    $cu = $this->_em->getRepository('App:CircolareUtente')->findOneBy(['circolare' => $circolare,
      'utente' => $utente]);
    if ($cu && !$cu->getConfermata()) {
      // imposta conferma di lettura
      $ora = new \DateTime();
      $cu
        ->setConfermata($ora)
        ->setLetta($ora);
      // memorizza dati
      $this->_em->flush();
      // conferma inserita
      return true;
    }
    // conferma non inserita
    return false;
  }

  /**
   * Conferma la lettura della circolare alla classe
   *
   * @param Classe $classe Classe a cui è stata letta la circolare
   * @param int $id ID della circolare (0 indica tutte)
   *
   * @return array Lista delle circolari lette
   */
  public function firmaClasse(Classe $classe, $id) {
    $firme = array();
    // query
    $circolari = $this->_em->getRepository('App:CircolareClasse')->createQueryBuilder('cc')
      ->join('cc.circolare', 'c')
      ->where('cc.classe=:classe AND cc.letta IS NULL AND c.pubblicata=:pubblicata')
      ->setParameters(['classe' => $classe, 'pubblicata' => 1]);
    if ($id > 0) {
      // singola circolare
      $circolari->andWhere('c.id=:id')->setParameter('id', $id);
    }
    $circolari = $circolari
      ->orderBy('c.numero', 'ASC')
      ->getQuery()
      ->getResult();
    // firma circolare
    $ora = new \DateTime();
    foreach ($circolari as $c) {
      $c->setLetta($ora);
      $this->_em->flush();
      $firme[] = $c->getCircolare();
    }
    // restituisce lista circolari firmate
    return $firme;
  }

  /**
   * Restituisce le statistiche sulla lettura della circolare
   *
   * @param Circolare $circolare Circolare da firmare
   *
   * @return array Dati formattati come array associativo
   */
  public function statistiche(Circolare $circolare) {
    $dati = array();
    $dati['ALU'] = array(1,1);
    $dati['GEN'] = array(1,1);
    $dati['ATA'] = array(1,1);
    $dati['DSGA'] = array(1,1);
    $dati['DOC'] = array(1,1);
    $dati['COORD'] = array(1,1);
    // lettura utenti
    $sql = "SELECT u.ruolo,(cl.id IS NOT NULL) AS coord,(u.tipo='D') AS segr,COUNT(c.id) AS tot,COUNT(cu.letta) AS lette ".
      "FROM gs_circolare AS c,gs_circolare_utente AS cu,gs_utente AS u ".
      "LEFT join gs_classe AS cl ON (u.id=cl.coordinatore_id) ".
      "WHERE c.id=:id AND c.id=cu.circolare_id AND u.id=cu.utente_id ".
      "GROUP by u.ruolo,coord,segr";
    $query = $this->_em->getConnection()->prepare($sql);
    $stat = $query->execute(['id' => $circolare->getId()]);
    foreach ($stat->fetchAllAssociative() as $s) {
      switch ($s['ruolo']) {
        case 'ALU':
          $dati['ALU'] = array($s['tot'], $s['lette']);
          break;
        case 'GEN':
          $dati['GEN'] = array($s['tot'], $s['lette']);
          break;
        case 'ATA':
          if ($s['segr']) {
            $dati['DSGA'] = array($s['tot'], $s['lette']);
          } else {
            $dati['ATA'] = array($s['tot'], $s['lette']);
          }
          break;
        case 'DOC':
        case 'STA':
          if ($s['coord']) {
            if (isset($dati['COORD'])) {
              $dati['COORD'] = array($s['tot'] + $dati['COORD'][0], $s['lette'] + $dati['COORD'][1]);
            } else {
              $dati['COORD'] = array($s['tot'], $s['lette']);
            }
          }
          if (isset($dati['DOC'])) {
            $dati['DOC'] = array($s['tot'] + $dati['DOC'][0], $s['lette'] + $dati['DOC'][1]);
          } else {
            $dati['DOC'] = array($s['tot'], $s['lette']);
          }
          break;
      }
    }
    // lettura classi
    $sql = "SELECT COUNT(*) AS tot,COUNT(cc.letta) AS lette ".
      "FROM gs_circolare AS c, gs_circolare_classe AS cc, gs_classe AS cl ".
      "WHERE c.id=:id AND c.id=cc.circolare_id AND cl.id=cc.classe_id";
    $query = $this->_em->getConnection()->prepare($sql);
    $stat = $query->execute(['id' => $circolare->getId()]);
    $stat = $stat->fetchAll();
    if ($stat[0]['tot'] == 0) {
      $dati['CLASSI'] = array(1, 1, []);
    } else {
      $dati['CLASSI'] = array($stat[0]['tot'], $stat[0]['lette'], []);
    }
    if ($stat[0]['tot'] > $stat[0]['lette']) {
      // lista classi in cui va letta
      $classi = $this->createQueryBuilder('c')
        ->select("CONCAT(cl.anno,'ª ',cl.sezione) AS nome")
        ->join('App:CircolareClasse', 'cc', 'WITH', 'cc.circolare=c.id')
        ->join('cc.classe', 'cl')
        ->where('c.id=:id AND cc.letta IS NULL')
        ->setParameters(['id' => $circolare->getId()])
        ->orderBy('cl.anno,cl.sezione', 'ASC')
        ->getQuery()
        ->getScalarResult();
      $dati['CLASSI'][2] = array_column($classi, 'nome');
    }
    // restituisce i dati
    return $dati;
  }

  /**
   * Restituisce la lista degli utenti a cui deve essere inviata una notifica per la circolare indicata
   *
   * @param Circolare $circolare Circolare da notificare
   *
   * @return array Lista degli utenti
   */
  public function notifica(Circolare $circolare) {
    // legge destinatari
    $destinatari = $this->_em->getRepository('App:CircolareUtente')->createQueryBuilder('cu')
      ->select('(cu.utente) AS utente')
      ->join('cu.circolare', 'c')
      ->where('c.id=:circolare AND c.pubblicata=:pubblicata AND cu.letta IS NULL')
      ->setParameters(['circolare' => $circolare, 'pubblicata' => 1])
      ->getQuery()
      ->getArrayResult();
    // restituisce lista utenti
    return array_column($destinatari, 'utente');
  }

  /**
   * Restituisce la lista delle circolari rispondenti alle condizioni di ricerca.
   *
   * @param array $cerca Lista dei criteri di ricerca
   * @param int $pagina Pagina corrente
   * @param int $limite Numero di elementi per pagina
   * @param Utente $utente Destinatario delle circolari
   *
   * @return array Dati formattati come array associativo
   */
  public function lista($cerca, $pagina, $limite, Utente $utente) {
    $dati = array();
    // legge circolari
    $query = $this->createQueryBuilder('c')
      ->where('c.pubblicata=:pubblicata')
      ->setParameter('pubblicata', 1)
      ->orderBy('c.data', 'DESC')
      ->addOrderBy('c.numero', 'DESC');
    if ($cerca['visualizza'] != 'T') {
      // solo circolari destinate all'utente
      $query
        ->join('App:CircolareUtente', 'cu', 'WITH', 'cu.circolare=c.id AND cu.utente=:utente')
        ->setParameter('utente', $utente);
      if ($cerca['visualizza'] == 'D') {
        // solo quelle da leggere
        $query
          ->andWhere('cu.letta IS NULL');
      }
    }
    if ($cerca['mese']) {
      // filtra per data
      $data = explode('-', $cerca['mese']);
      $query
        ->andWhere('YEAR(c.data)=:anno AND MONTH(c.data)=:mese')
        ->setParameter('anno', intval($data[0]))
        ->setParameter('mese', intval($data[1]));
    }
    if ($cerca['oggetto']) {
      // filtra per oggetto
      $query
        ->andWhere('c.oggetto LIKE :oggetto')
        ->setParameter('oggetto', '%'.$cerca['oggetto'].'%');
    }
    $dati['lista'] = $this->paginate($query->getQuery(), $pagina, $limite);
    // aggiunge dati di lettura
    foreach ($dati['lista'] as $c) {
      $dati['stato'][$c->getId()] = $this->_em->getRepository('App:CircolareUtente')->findOneBy([
        'circolare' => $c, 'utente' => $utente]);
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Restituisce le circolari non lette e destinate agli alunni della classe indicata
   *
   * @param Classe $classe Classe a cui sono indirizzate le circolari
   *
   * @return array Lista di circolari da leggere
   */
  public function listaCircolariClasse(Classe $classe) {
    // lista circolari
    $circolari = $this->createQueryBuilder('c')
      ->join('App:CircolareClasse', 'cc', 'WITH', 'cc.circolare=c.id AND cc.classe=:classe')
      ->where('c.pubblicata=:pubblicata AND cc.letta IS NULL')
      ->setParameters(['pubblicata' => 1, 'classe' => $classe])
      ->orderBy('c.data', 'ASC')
      ->addOrderBy('c.numero', 'ASC')
      ->getQuery()
      ->getArrayResult();
    // restituisce dati
    return $circolari;
  }

}
