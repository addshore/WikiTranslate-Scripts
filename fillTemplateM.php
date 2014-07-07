<?php

echo "Loading\n";
require_once( __DIR__ . '/vendor/autoload.php' );
$wtApi = new \Mediawiki\Api\MediawikiApi( 'http://wikitranslate.org//w/api.php' );
$ruApi = new \Mediawiki\Api\MediawikiApi( 'http://ru.wikipedia.org/w/api.php' );
$wdApi = new \Mediawiki\Api\MediawikiApi( 'http://www.wikidata.org/w/api.php' );
$wtApi->login( new \Mediawiki\Api\ApiUser( 'USERNAME', 'PASSWORD' ) );
echo "Logged in!\n";
$wikiTranslate = new \Mediawiki\Api\MediawikiFactory( $wtApi );
$wikidata = new \Wikibase\Api\WikibaseFactory( $wdApi );
$ruwiki = new \Mediawiki\Api\MediawikiFactory( $ruApi );
echo "Looking for Template:M Transclusions\n";
$pages = $wikiTranslate->newPageListGetter()->getPageListFromPageTransclusions( 'Template:M' );
echo "Found " . count( $pages->toArray() ) . " pages that need checking\n";
foreach( $pages->toArray() as $page ) {
	echo '.';
	/** @var Mediawiki\DataModel\Page $page */
	$currentRevision = $wikiTranslate->newPageGetter()->getFromPage( $page )->getRevisions()->getLatest();
	$text = $currentRevision->getContent()->getNativeData();

	$hasChanged = false;
	$e1 = explode( '{{', $text, 2 );
	$e2 = explode( '}}', $e1[1], 2 );
	$templateParts = explode( '|', $e2[0] );
	$templatePartsBefore = $templateParts;

	if( preg_match( '/\[\[wikipedia:ru:(.+)\]\]/', $templateParts[6], $matches ) ){
		$ruTitle = $matches[1];
		$ruPageInfo = $ruApi->getAction( 'query', array(
			'prop' => 'info',
			'redirects' => true,
			'titles' => $ruTitle,
		) );
		$ruTitle = array_shift( $ruPageInfo['query']['pages'] );
		$ruTitle = $ruTitle['title'];

		$currentItemRevision = $wikidata->newRevisionGetter()->getFromSiteAndTitle( 'ruwiki', $ruTitle );
		if( !$currentItemRevision ) {
			//echo "Not found an item for ruwiki:$ruTitle\n";
		} else {
			/** @var Wikibase\DataModel\Entity\Item $item */
			$item = $currentItemRevision->getContent()->getNativeData();
			//echo "Got Item " . $item->getId()->getSerialization() . "\n";
			$claims = new \Wikibase\DataModel\Claim\Claims( $item->getClaims() );
			$dob = $claims->getClaimsForProperty( \Wikibase\DataModel\Entity\PropertyId::newFromNumber( 569 ) )->getArrayCopy();
			$dod = $claims->getClaimsForProperty( \Wikibase\DataModel\Entity\PropertyId::newFromNumber( 570 ) )->getArrayCopy();
			if( count( $dob ) === 1 && count( $dod ) === 1 ) {
				$dob = array_shift( $dob );
				$dod = array_shift( $dod );
				/** @var \Wikibase\DataModel\Claim\Claim $dob */
				/** @var \Wikibase\DataModel\Claim\Claim $dod */
				if( $dob->getMainSnak()->getType() === 'value' && $dod->getMainSnak()->getType() === 'value' ) {
					$dob = $dob->getMainSnak();
					$dod = $dod->getMainSnak();
					/** @var Wikibase\DataModel\Snak\PropertyValueSnak $dob */
					/** @var Wikibase\DataModel\Snak\PropertyValueSnak $dod */
					//echo "Formatting dates\n";
					$dob = $wikidata->newValueFormatter()->format( $dob->getDataValue(), 'time' );
					$dod = $wikidata->newValueFormatter()->format( $dod->getDataValue(), 'time' );

					$templateParts[3] = $dob;
					$templateParts[4] = $dod;
					if( $templateParts[3] !== $templatePartsBefore[3] || $templateParts[4] !== $templatePartsBefore[4] ) {
						$hasChanged = true;
					}
				} else {
					//echo "Either DOB or DOD is not a value snak";
				}
			} else {
				//echo "Cant find both a DOB and a DOD\n";
			}

			try{
				$siteLink = $item->getSiteLinkList()->getBySiteId( 'enwiki' );
				if( array_key_exists( 6, $templateParts ) && substr( $templateParts[6], 0, 2 ) === 'l=' ) {

					$templateParts[6] = str_replace( '[[wikipedia:]]', '[[wikipedia:' . $siteLink->getPageName() . ']]', $templateParts[6] );

					if( $templatePartsBefore[6] !== $templateParts[6] ) {
						$hasChanged = true;
					}

					$templateParts[2] = str_replace( ' ', '', $siteLink->getPageName() );

					if( $templatePartsBefore[2] !== $templateParts[2] ) {
						$hasChanged = true;
					}
				}

			} catch( OutOfBoundsException $e ) {
				//echo "Cant find enwiki sitelink\n";
			}
		}
	}

	if( $hasChanged ) {
		$text = $e1[0] . '{{' . implode( '|', $templateParts ) . '}}' . $e2[1];
		$newRevision = new \Mediawiki\DataModel\Revision(
			new \Mediawiki\DataModel\WikitextContent( $text ),
			$currentRevision->getPageId()
		);

		echo "\nSaving new Revision for " . $page->getTitle();
		$wikiTranslate->newRevisionSaver()->save(
			$newRevision,
			new \Mediawiki\DataModel\EditInfo(
				'Adding information to template from Wikidata',
				\Mediawiki\DataModel\EditInfo::MINOR
			)
		);
		//die( "Oh My God It Worked\n" );
	}

}
