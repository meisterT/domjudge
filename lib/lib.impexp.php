<?php declare(strict_types=1);
/**
 * Functions for importing / exporting.
 *
 * Part of the DOMjudge Programming Contest Jury System and licenced
 * under the GNU GPL. See README and COPYING for details.
 */

/** Import functions **/
function tsv_import($fmt)
{
    echo "<p>Importing $fmt.</p>\n\n";

    // generic for each tsv format
    checkFileUpload($_FILES['tsv']['error']);
    // read entire file into an array
    $content = file($_FILES['tsv']['tmp_name']);
    // the first line of the tsv is always the format with a version number.
    // currently we hardcode version 1 because there are no others
    $version = rtrim(array_shift($content));
    // Two variants are in use: one where the first token is a static string
    // "File_Version" and the second where it's the type, e.g. "groups".
    $versionmatch = '1';
    if ($fmt == 'teams') {
        $versionmatch = '[12]';
    }
    if (!preg_match("/^(File_Version|$fmt)\t$versionmatch$/i", $version)) {
        error("Unknown format or version: $version != $versionmatch");
    }

    // select each format and call appropriate functions.
    // the prepare function parses the tsv, checks if the data looks sane,
    // and delivers it in the format for the setter function. The latter
    // updates the database (so only after all lines have first been
    // read and checked).
    switch ($fmt) {
        case 'groups':
            $data = tsv_groups_prepare($content);
            $cnt = tsv_groups_set($data);
            break;
        case 'teams':
            $data = tsv_teams_prepare($content);
            $cnt = tsv_teams_set($data);
            break;
        case 'accounts':
            $data = tsv_accounts_prepare($content);
            $cnt = tsv_accounts_set($data);
            break;
        default: error("Unknown format");
    }

    echo "<p>$cnt items imported</p>";
}


function tsv_groups_prepare($content)
{
    $data = array();
    $l = 1;
    foreach ($content as $line) {
        $l++;
        $line = explode("\t", trim($line));
        if (! is_numeric($line[0])) {
            error("Invalid id format on line $l");
        }
        $data[] = array(
            'categoryid' => @$line[0],
            'name' =>  @$line[1]);
    }

    return $data;
}


function tsv_groups_set($data)
{
    global $DB, $cdata;
    $cnt = 0;
    foreach ($data as $row) {
        $replacecnt = $DB->q("RETURNAFFECTED REPLACE INTO team_category SET %S", $row);
        if (isset($cdata['cid'])) {
            eventlog('team_category', $row['categoryid'], $replacecnt == 1 ? 'create' : 'update', $cdata['cid']);
        }
        auditlog('team_category', $row['categoryid'], 'replaced', 'imported from tsv');
        $cnt++;
    }
    return $cnt;
}

function tsv_teams_prepare($content)
{
    $data = [];
    $l    = 1;
    foreach ($content as $line) {
        $l++;
        $line = explode("\t", trim($line));

        // teams.tsv contains data pertaining both to affiliations and teams.
        // hence return data for both tables.

        // we may do more integrity/format checking of the data here.

        // Set external ID's to null if they are not given
        $teamExternalId = @$line[1];
        if (empty($teamExternalId)) {
            $teamExternalId = null;
        }
        $affiliationExternalid = preg_replace('/^INST-/', '', @$line[7]);
        if (empty($affiliationExternalid)) {
            // TODO: note that when we set this external ID to NULL, we *will* add team affiliations
            // multiple times, as the $affilid query in tsv_teams_set will not find an affiliation.
            // We might want to change that to also search on shortname and/or name?
            $affiliationExternalid = null;
        }

        // Set team ID to external ID if it has the literal value 'null' and the external ID is numeric
        $teamId = @$line[0];
        if ($teamId === 'null' && is_numeric($teamExternalId)) {
            $teamId = (int)$teamExternalId;
        }

        $data[] = [
            'team' => [
                'teamid' => $teamId,
                'externalid' => $teamExternalId,
                'categoryid' => @$line[2],
                'name' => @$line[3],
            ],
            'team_affiliation' => [
                'shortname' => !empty(@$line[5]) ? @$line[5] : $affiliationExternalid,
                'name' => @$line[4],
                'country' => @$line[6],
                'externalid' => $affiliationExternalid,
            ]
        ];
    }

    return $data;
}


function tsv_teams_set($data)
{
    global $DB, $cdata;
    $cnt = 0;
    $createdAffiliations = [];
    $createdTeams = [];
    $updatedTeams = [];
    foreach ($data as $row) {
        // it is legitimate that a team has no affiliation. Do not add it then.
        if (!empty($row['team_affiliation']['shortname'])) {
            // First look up if the affiliation already exists.
            $affilid = $DB->q("MAYBEVALUE SELECT affilid FROM team_affiliation
                               WHERE externalid = %s LIMIT 1",
                              $row['team_affiliation']['externalid']);
            if (empty($affilid)) {
                $affilid = $DB->q("RETURNID INSERT INTO team_affiliation SET %S",
                                  $row['team_affiliation']);

                $createdAffiliations[] = $affilid;
                auditlog('team_affiliation', $affilid, 'added', 'imported from tsv');
            }
            $row['team']['affilid'] = $affilid;
        }
        if (!empty($row['team']['categoryid'])) {
            $categid = $DB->q("MAYBEVALUE SELECT categoryid FROM team_category
                               WHERE categoryid = %s", $row['team']['categoryid']);
            if (empty($categid)) {
                $DB->q("INSERT INTO team_category (categoryid, name) VALUES (%s, %s)",
                    $row['team']['categoryid'], $row['team']['categoryid'] . " - auto-create during import");
                auditlog('team_category', $categoryid, 'added', 'imported from tsv');
            }
        }
        $replacecnt = $DB->q("RETURNAFFECTED REPLACE INTO team SET %S", $row['team']);

        if ($replacecnt == 1) {
            $createdTeams[] = $row['team']['teamid'];
        } else {
            $updatedTeams[] = $row['team']['teamid'];
        }
        auditlog('team', $row['team']['teamid'], 'replaced', 'imported from tsv');
        $cnt++;
    }

    if (isset($cdata['cid'])) {
        if (!empty($createdAffiliations)) {
            eventlog('team_affiliation', $createdAffiliations, 'create', $cdata['cid']);
        }
        if (!empty($createdTeams)) {
            eventlog('team', $createdTeams, 'create', $cdata['cid']);
        }
        if (!empty($updatedTeams)) {
            eventlog('team', $updatedTeams, 'update', $cdata['cid']);
        }
    }

    return $cnt;
}


function tsv_accounts_prepare($content)
{
    global $DB;
    $data = array();
    $l = 1;
    $teamroleid = $DB->q('VALUE SELECT roleid FROM role WHERE role = %s', 'team');
    $juryroleid = $DB->q('VALUE SELECT roleid FROM role WHERE role = %s', 'jury');
    $adminroleid = $DB->q('VALUE SELECT roleid FROM role WHERE role = %s', 'admin');

    $jurycatid = $DB->q('MAYBEVALUE SELECT categoryid FROM team_category WHERE name = "Jury"');
    if (!$jurycatid) {
        $jurycatid = $DB->q('RETURNID INSERT INTO team_category (name,sortorder,visible)
                             VALUES ("Jury", 100, 0)');
    }

    foreach ($content as $line) {
        $l++;
        $line = explode("\t", trim($line));

        $teamid = $juryteam = null;
        $roleids = array();
        switch ($line[0]) {
            case 'admin':
                $roleids[] = $adminroleid;
                break;
            case 'judge':
                $roleids[] = $juryroleid;
                $roleids[] = $teamroleid;
                $juryteam = array('name' => $line[1], 'categoryid' => $jurycatid, 'members' => $line[1]);
                break;
            case 'team':
                $roleids[] = $teamroleid;
                // For now we assume we can find the teamid by parsing
                // the username and taking the largest suffix number.
                // Note that https://clics.ecs.baylor.edu/index.php/Contest_Control_System_Requirements#accounts.tsv
                // assumes team accounts of the form "team-nnn" where
                // nnn is a zero-padded team number.
                $teamid = preg_replace('/^[^0-9]*0*([0-9]+)$/', '\1', $line[2]);
                if (!preg_match('/^[0-9]+$/', $teamid)) {
                    error('cannot parse team id on line '.$l.' from "'.$line[2].'"');
                }
                if (!$DB->q('MAYBEVALUE SELECT teamid FROM team WHERE teamid = %i', $teamid)) {
                    error("unknown team id $teamid on line $l");
                }
                break;
            case 'analyst':
                // Ignore type analyst for now. We don't have a useful mapping yet.
                continue 2;
            default:
                error('unknown role on line ' . $l . ': ' . $line[0]);
        }

        // accounts.tsv contains data pertaining both to users and userroles.
        // hence return data for both tables.

        // we may do more integrity/format checking of the data here.
        $data[] = array(
            'user' => array(
                'name' => $line[1],
                'username' => $line[2],
                'password' => dj_password_hash($line[3]),
                'teamid' => $teamid
            ),
            'userroles' => $roleids,
            'team' => $juryteam,
        );
    }

    return $data;
}


function tsv_accounts_set($data)
{
    global $DB;
    $cnt = 0;
    foreach ($data as $row) {
        if (! empty($row['team'])) {
            $teamid = $DB->q("MAYBEVALUE SELECT teamid FROM team WHERE name = %s AND categoryid = %i",
                             $row['team']['name'], $row['team']['categoryid']);
            if (is_null($teamid)) {
                $teamid = $DB->q("RETURNID INSERT INTO team SET %S", $row['team']);
            }
            eventlog('team', $teamid, 'create');
            auditlog('team', $teamid, 'added', 'imported from tsv, autocreated for judge');
            $row['user']['teamid'] = $teamid;
        }
        $DB->q("REPLACE INTO user SET %S", $row['user']);
        $userid = $DB->q("VALUE SELECT userid FROM user WHERE username = %s", $row['user']['username']);
        auditlog('user', $userid, 'replaced', 'imported from tsv');
        foreach ($row['userroles'] as $roleid) {
            $userrole_data = array(
                'userid' => $userid,
                'roleid' => $roleid
            );
            $DB->q("INSERT INTO userrole SET %S", $userrole_data);
            auditlog('userrole', $userid, 'insert', 'imported from tsv');
        }
        $cnt++;
    }
    return $cnt;
}


/** Export functions **/
$extid_to_name = array();

// sort data array according to rank and name
function cmp_extid_name($a, $b)
{
    global $extid_to_name;
    if ($a[1] != $b[1]) {
        // Honorable mention has no rank
        if ($a[1] == "") {
            return 1;
        } elseif ($b[1] == "") {
            return -11;
        }
        return $a[1] - $b[1];
    }
    $name_a = $extid_to_name[$a[0]] ?? '';
    $name_b = $extid_to_name[$b[0]] ?? '';
    $collator = new Collator('en');
    return $collator->compare($name_a, $name_b);
}

function tsv_results_get()
{
    // we'll here assume that the requested file will be of the current contest,
    // as all our scoreboard interfaces do
    // 1 	External ID 	24314 	integer
    // 2 	Rank in contest 	1 	integer
    // 3 	Award 	Gold Medal 	string
    // 4 	Number of problems the team has solved 	4 	integer
    // 5 	Total Time 	534 	integer
    // 6 	Time of the last submission 	233 	integer
    // 7 	Group Winner 	North American 	string
    global $cdata, $DB, $extid_to_name;

    $categs = $DB->q('COLUMN SELECT categoryid FROM team_category WHERE visible = 1');
    $sb = genScoreBoard($cdata, true, array('categoryid' => $categs));
    $additional_bronze = $DB->q('VALUE SELECT b FROM contest WHERE cid = %i', $cdata['cid']);
    $extid_to_name = $DB->q('KEYVALUETABLE SELECT t.externalid, a.name
                             FROM team t
                             LEFT JOIN team_affiliation a USING (affilid)
                             WHERE t.externalid IS NOT NULL ORDER BY t.externalid');

    $numteams = sizeof($sb['scores']);

    // determine number of problems solved by median team
    $cnt = 0;
    foreach ($sb['scores'] as $teamid => $srow) {
        $cnt++;
        $median = $srow['num_points'];
        if ($cnt > $numteams/2) { // XXX: lower or upper median?
            break;
        }
    }

    $ranks = array();
    $group_winners = array();
    $data = array();
    foreach ($sb['scores'] as $teamid => $srow) {
        $maxtime = -1;
        foreach ($sb['matrix'][$teamid] as $prob) {
            $maxtime = max($maxtime, scoretime((float)$prob['time']));
        }

        $rank = $srow['rank'];
        $num_correct = $srow['num_points'];
        if ($rank <= 4) {
            $awardstring = "Gold Medal";
        } elseif ($rank <= 8) {
            $awardstring = "Silver Medal";
        } elseif ($rank <= 12 + $additional_bronze) {
            $awardstring = "Bronze Medal";
        } elseif ($num_correct >= $median) {
            // teams with equally solved number of problems get the same rank
            if (!isset($ranks[$num_correct])) {
                $ranks[$num_correct] = $rank;
            }
            $rank = $ranks[$num_correct];
            $awardstring = "Ranked";
        } else {
            $awardstring = "Honorable";
            $rank = "";
        }

        $groupwinner = "";
        if (!isset($group_winners[$srow['categoryid']])) {
            $group_winners[$srow['categoryid']] = true;
            $groupwinner = $DB->q('VALUE SELECT name FROM team_category WHERE categoryid = %i', $srow['categoryid']);
        }

        $data[] = array(@$sb['teams'][$teamid]['externalid'],
                        $rank, $awardstring, $srow['num_points'],
                        $srow['total_time'], $maxtime, $groupwinner);
    }

    // sort by rank/name
    uasort($data, 'cmp_extid_name');

    return $data;
}
