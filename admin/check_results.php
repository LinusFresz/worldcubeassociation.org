<?php
#----------------------------------------------------------------------
#   Initialization and page contents.
#----------------------------------------------------------------------

$currentSection = 'admin';
require( '../includes/_header.php' );
require( '../includes/_check.php' );
analyzeChoices();
adminHeadline( 'Check results' );
showDescription();
showChoices();

if ( $chosenWhich == 'recent' )
  $dateCondition = "AND (year*10000+month*100+day >= CURDATE() - INTERVAL 3 MONTH)";

switch ( $chosenWhat ){
  case 'individual':
    checkIndividually();
    break;
  case 'ranks':
    checkRelatively();
    break;
  case 'duplicates':
    checkDuplicates();
    break;
}

require( '../includes/_footer.php' );

#----------------------------------------------------------------------
function showDescription () {
#----------------------------------------------------------------------

  echo "<p style='width:45em'>First, check the results individually, according to our <a href='check_results.txt'>checking procedure</a> (mostly that value1-value5 make sense and that average and best are correct).</p>\n";

  echo "<p style='width:45em'>Then, once they're correct individually, check ranks. This compares results with others in the same round to check each competitor's place. Differences between calculated and stored places can be agreed to and then executed on the bottom of the page.\n";

  echo "<p style='width:45em'>You can also print duplicate results that happened during a competition. It might reveal errors in score taking, although some duplicates can be due to chance.</p>\n";

  echo "<p style='width:45em'>Usually you should check only the recent results (past three months), it's faster and it hides exceptions that shall remain (once they're older than three months).</p>\n";

  echo "<hr />\n";
}

#----------------------------------------------------------------------
function analyzeChoices () {
#----------------------------------------------------------------------
  global $chosenWhich, $chosenWhat;

  $chosenWhich = getNormalParamDefault( 'which', 'recent' );
  $chosenWhat = getNormalParamDefault( 'what', 'individual' );
}

#----------------------------------------------------------------------
function showChoices () {
#----------------------------------------------------------------------
  global $chosenWhich, $chosenWhat;

  displayChoices( array(
    'Check',
    choice( 'which', '', array( array( 'recent', 'recent' ), array( 'all', 'all' )), $chosenWhich ),
    choice( 'what', '', array( array( 'individual', 'individual results' ), array( 'ranks', 'ranks' ), array( 'duplicates', 'duplicates' )), $chosenWhat ),
    choiceButton( true, 'go', 'Go' )
  ));
}

#----------------------------------------------------------------------
function checkIndividually () {
#----------------------------------------------------------------------
  global $dateCondition, $chosenWhich;

  echo "<hr /><p>Checking <b>" . $chosenWhich . " individual results</b>... (wait for the result message box at the end)</p>\n";

  #--- Get all results (id, values, format, round).
  $dbResult = mysql_query("
    SELECT
      result.id, formatId, roundId, personId, competitionId, eventId, result.countryId,
      value1, value2, value3, value4, value5, best, average
    FROM Results result, Competitions competition
    WHERE competition.id = competitionId
      $dateCondition
    ORDER BY formatId, competitionId, eventId, roundId, result.id
  ")
    or die("<p>Unable to perform database query.<br/>\n(" . mysql_error() . ")</p>\n");

  #--- Build id sets
  $countryIdSet     = array_flip( getAllIDs( dbQuery( "SELECT id FROM Countries" )));
  $competitionIdSet = array_flip( getAllIDs( dbQuery( "SELECT id FROM Competitions" )));
  $eventIdSet       = array_flip( getAllIDs( dbQuery( "SELECT id FROM Events" )));
  $formatIdSet      = array_flip( getAllIDs( dbQuery( "SELECT id FROM Formats" )));
  $roundIdSet       = array_flip( getAllIDs( dbQuery( "SELECT id FROM Rounds" )));

  #--- Process the results.
  $badIds = array();
  echo "<pre>\n";
  while( $result = mysql_fetch_array( $dbResult )){
    if( $error = checkResult( $result, $countryIdSet, $competitionIdSet, $eventIdSet, $formatIdSet, $roundIdSet )){
      extract( $result );
      echo "Error: $error\nid:$id format:$formatId round:$roundId";
      echo " ($value1,$value2,$value3,$value4,$value5) best+average($best,$average)\n";
      echo "$personId   $countryId   $competitionId   $eventId\n\n";
      $badIds[] = $id;
    }
  }
  echo "</pre>";

  #--- Free the results.
  mysql_free_result( $dbResult );

  #--- Tell the result.
  noticeBox2(
    ! count( $badIds ),
    "All checked results pass our checking procedure successfully.<br />" . wcaDate(),
    count( $badIds ) . " errors found. Get them with this:<br /><br />SELECT * FROM Results WHERE id in (" . implode( ', ', $badIds ) . ")"
  );
}

#----------------------------------------------------------------------
function checkRelatively () {
#----------------------------------------------------------------------
  global $dateCondition, $chosenWhich;

  echo "<hr /><p>Checking <b>" . $chosenWhich . " ranks</b>... (wait for the result message box at the end)</p>\n";

  #--- Get all results (except the trick-duplicated (old) multiblind)
  $rows = dbQueryHandle("
    SELECT   result.id, competitionId, eventId, roundId, average, best, pos, personName
    FROM     Results result, Competitions competition
    WHERE    competition.id = competitionId
      $dateCondition
      AND    (( eventId <> '333mbf' ) OR (( competition.year = 2009 ) AND ( competition.month > 1 )) OR ( competition.year > 2009 ))
    ORDER BY year desc, month desc, day desc, competitionId, eventId, roundId, if(average>0, average, 2147483647), if(best>0, best, 2147483647), pos
  ");

  #--- Begin the form
  echo "<form action='check_results_ACTION.php' method='post'>\n";

  #--- Check the pos values
  $prevRound = $shownRound = '';
  $wrongs = $wrongRounds = 0;
  $wrongComp = array();
  while( $row = mysql_fetch_row( $rows ) ) {
    list( $resultId, $competitionId, $eventId, $roundId, $average, $best, $storedPos, $personName ) = $row;
    $round = "$competitionId|$eventId|$roundId";
    $result = "$average|$best";
    if ( $round != $prevRound )
      $ctr = $calcedPos = 1;
    if ( $ctr > 1  &&  $result != $prevResult )
      $calcedPos = $ctr;
    if ( $storedPos != $calcedPos ) {

      #--- Before the first difference in a round, show the round's full results
      if ( $round != $shownRound ) {
        $wrongRounds++;
        $wrongComp[$competitionId] = true;
        echo "<p style='margin-top:2em; margin-bottom:0'><a href='http://worldcubeassociation.org/results/c.php?i=$competitionId&allResults=1#e{$eventId}_$roundId'>$competitionId - $eventId - $roundId</a></p>";
        showCompetitionResults( $competitionId, $eventId, $roundId );
        $shownRound = $round;
      }

      #--- Show each difference, with a checkbox to agree
      $change = sprintf('%+d', $calcedPos-$storedPos);
      $checkbox = "<input type='checkbox' name='setpos$resultId' value='$calcedPos' />";
      printf( "$checkbox Place $storedPos should be place $calcedPos (change by $change) --- $personName<br />" );
      $wrongs++;
    }
    $prevRound = $round;
    $prevResult = $result;
    $ctr++;
  }
  mysql_free_result( $rows );

  #--- Tell the result.
  $date = wcaDate();
  noticeBox2(
    ! $wrongs,
    "We agree about all checked places.<br />$date",
    "<p>Darn! We disagree: $wrongs possibly wrong places, in $wrongRounds rounds, in " . count($wrongComp) . " competitions<br /><br />$date</p>"
    ."<p>Choose the changes you agree with above, then click the 'Execute...' button below. It will result in something like the following.</p>"
    ."<pre>I'm doing this:\n"
    ."UPDATE Results SET pos=111 WHERE id=11111\n"
    ."UPDATE Results SET pos=222 WHERE id=22222\n"
    ."UPDATE Results SET pos=333 WHERE id=33333\n"
    ."</pre>"
  );

  #--- If differences were found, offer to fix them.
  if( $wrongs )
    echo "<center><input type='submit' value='Execute the agreed changes!' /></center>\n";

  #--- Finish the form.
  echo "</form>\n";
}

#----------------------------------------------------------------------
function showCompetitionResults ( $competitionId, $eventId, $roundId ) {
#----------------------------------------------------------------------

  # NOTE: This is mostly a copy of the same function in competition_results.php

  #--- Get the results.
  $competitionResults = getCompetitionResults( $competitionId, $eventId, $roundId );

  tableBegin( 'results', 8 );

  $prevEventId = $prevRoundId = '';
  foreach( $competitionResults as $result ){
    extract( $result );

    $isNewEvent = ($eventId != $prevEventId);
    $isNewRound = ($roundId != $prevRoundId);

    #--- Welcome new rounds.
    if( $isNewEvent  ||  $isNewRound ){

      $anchors = ($isNewEvent ? "$eventId " : "") . "${eventId}_$roundId";
      $eventHtml = eventLink( $eventId, $eventName );
      $caption = spaced( array( $eventHtml, $roundName, $formatName ));
      tableCaptionNew( false, $anchors, $caption );

      $headerAverage    = ($formatId == 'a'  ||  $formatId == 'm') ? 'Average' : '';
      $headerAllResults = ($formatId != '1') ? 'Result Details' : '';
      tableHeader( explode( '|', "Place|Person|Best||$headerAverage||Citizen of|$headerAllResults" ),
                   array( 0 => 'class="r"', 2 => 'class="R"', 4 => 'class="R"', 7 => 'class="f"' ));
    }

    #--- One result row.
    tableRow( array(
      $pos,
      personLink( $personId, $personName ),
      formatValue( $best, $valueFormat ),
      $regionalSingleRecord,
      formatValue( $average, $valueFormat ),
      $regionalAverageRecord,
      $countryName,
      formatAverageSources( $formatId != '1', $result, $valueFormat )
    ));

    $prevEventId = $eventId;
    $prevRoundId = $roundId;
  }

  tableEnd();
}

#----------------------------------------------------------------------
function getCompetitionResults ( $competitionId, $eventId, $roundId ) {
#----------------------------------------------------------------------

  # NOTE: This is mostly a copy of the same function in competition_results.php

  $order = "event.rank, round.rank, pos, average, best, personName";

  #--- Get and return the results.
  return dbQuery("
    SELECT
                     result.*,

      event.name      eventName,
      round.name      roundName,
      round.cellName  roundCellName,
      format.name     formatName,
      country.name    countryName,

      event.cellName  eventCellName,
      event.format    valueFormat
    FROM
      Results      result,
      Events       event,
      Rounds       round,
      Formats      format,
      Countries    country,
      Competitions competition
    WHERE ".randomDebug()."
      AND competitionId  = '$competitionId'
      AND competition.id = '$competitionId'
      AND eventId        = '$eventId'
      AND event.id       = '$eventId'
      AND roundId        = '$roundId'
      AND round.id       = '$roundId'
      AND format.id     = formatId
      AND country.id    = result.countryId
      AND (( event.id <> '333mbf' ) OR (( competition.year = 2009 ) AND ( competition.month > 1 )) OR ( competition.year > 2009 ))
    ORDER BY
      $order
  ");
}

#----------------------------------------------------------------------
function checkDuplicates () {
#----------------------------------------------------------------------
  global $dateCondition, $chosenWhich;

  echo "<hr /><p>Checking <b>" . $chosenWhich . " duplicate results</b>... (wait for the result message box at the end)</p>\n";

  #--- Get all duplicate results (except old-new multiblind)
  $rows = dbQuery("
    SELECT competitionId, value1, value2, value3, value4, value5, whoWhere FROM
      (SELECT competitionId, value1, value2, value3, value4, value5, count(*) ctr,
              group_concat(concat_ws('|',eventId,roundId,personName,personId) SEPARATOR  '||') whoWhere
       FROM Results, Competitions competition
       WHERE eventId<>'333mbo'
         AND competition.id = competitionId
             $dateCondition
       GROUP BY competitionId, value1, value2, value3, value4, value5) tmp
    WHERE ctr>1 AND (value1>0)+(value2>0)+(value3>0)+(value4>0)+(value5>0) >= 2
    ORDER BY greatest(value1, value2, value3, value4, value5) DESC

  ");

  tableBegin( 'results', 4 );
  foreach( $rows as $row ){
    extract( $row );
    $competition = getCompetition ( $competitionId );
    $competitionName = $competition['cellName'];
    $whos = explode( '||', $whoWhere);
    tableCaption( false, competitionLink ( $competitionId, $competitionName ) );
    tableHeader( explode( '|', "Person|Event|Round|Result Details" ),
                 array( 3 => 'class="f"' ));
    foreach( $whos as $who ){
      list( $eventId, $roundId, $personName, $personId ) = explode( '|', $who );
      tableRow(
        array(
          personLink( $personId, $personName ),
          eventCellName( $eventId ),
          roundCellName( $roundId ),
          formatAverageSources( true, $row, valueFormat( $eventId ))
        )
      );
    }
  }
  tableEnd();

  #--- Tell the result.
  $date = wcaDate();
  noticeBox2(
    count( $rows ) == 0,
    "No duplicate results where found.<br />$date",
    "Duplicate results where found.<br />$date"
  );
}

?>
