<?php
/**
 * AgriMatch Matching Engine
 *
 *   Level 1: Crop type  (fixed filter)
 *   Level 2: Quantity   (AGGREGATE: combined supply must reach the buyer's requirement)
 *
 * Location is NOT a matching criterion: produce from any location can match.
 *
 * PRIVACY: browse functions (getDemandCoverage, getListingCandidates, getIncomingRequestsForFarmer)
 * never select phone, email or address. Only the "accepted" functions return contact details.
 */

// ---------- UNIT HANDLING ----------
// Weight-based units convert to kg so quantities can be added together.
// Crates have no known weight, so they only combine with other crates.
const WEIGHT_UNIT_TO_KG = [
    'kg'        => 1,
    'tonnes'    => 1000,
    'bags_50kg' => 50,
    'bags_90kg' => 90,
];

function unitFamily($unit) {
    return $unit === 'crates' ? 'crates' : 'weight';
}

function unitFactor($unit) {
    return WEIGHT_UNIT_TO_KG[$unit] ?? 1;
}

function toBaseQuantity($quantity, $unit) {
    return (float) $quantity * unitFactor($unit);
}

function fromBaseQuantity($baseQuantity, $unit) {
    return $baseQuantity / unitFactor($unit);
}

// ---------- INTERNAL FETCHERS (include contact info; used for emails/validation only) ----------
function fetchListingInternal($conn, $listingId) {
    $stmt = $conn->prepare("
        SELECT p.*, uf.full_name AS farmer_name, uf.email AS farmer_email, uf.phone AS farmer_phone
        FROM produce_listings p
        JOIN farmer_details f ON f.farmer_id = p.farmer_id
        JOIN users uf ON uf.user_id = f.user_id
        WHERE p.listing_id = ?
    ");
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function fetchDemandInternal($conn, $demandId) {
    $stmt = $conn->prepare("
        SELECT d.*, ub.full_name AS buyer_name, ub.email AS buyer_email, ub.phone AS buyer_phone
        FROM demands d
        JOIN buyer_details b ON b.buyer_id = d.buyer_id
        JOIN users ub ON ub.user_id = b.user_id
        WHERE d.demand_id = ?
    ");
    $stmt->bind_param('i', $demandId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ---------- QUANTITY ACCOUNTING ----------
/**
 * Total quantity (in base units) already committed through ACCEPTED matches for a demand.
 * $demand must contain 'demand_id' and 'unit'.
 */
function getAcceptedBaseQuantity($conn, $demand) {
    $stmt = $conn->prepare("
        SELECT p.quantity, p.unit
        FROM matches m
        JOIN produce_listings p ON p.listing_id = m.listing_id
        WHERE m.demand_id = ? AND m.status = 'accepted'
    ");
    $stmt->bind_param('i', $demand['demand_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $family = unitFamily($demand['unit']);
    $total = 0.0;
    while ($row = $result->fetch_assoc()) {
        if (unitFamily($row['unit']) !== $family) continue;
        $total += toBaseQuantity($row['quantity'], $row['unit']);
    }
    $stmt->close();

    return $total;
}

// ---------- BUYER SIDE: CANDIDATE LISTINGS (Level 1 only; quantity is aggregated) ----------
/**
 * All available listings of the same crop, regardless of location or individual size.
 * No contact details are selected.
 */
function getCandidateListings($conn, $demand) {
    $sql = "
        SELECT p.*, u.full_name AS farmer_name,
               mt.match_id, mt.status AS match_status, mt.requested_by
        FROM produce_listings p
        JOIN farmer_details f ON f.farmer_id = p.farmer_id
        JOIN users u ON u.user_id = f.user_id
        LEFT JOIN matches mt ON mt.listing_id = p.listing_id AND mt.demand_id = ?
        WHERE p.status = 'available'
          AND p.crop_type = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $demand['demand_id'], $demand['crop_type']);
    $stmt->execute();
    $result = $stmt->get_result();

    $family = unitFamily($demand['unit']);
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        if (unitFamily($row['unit']) !== $family) continue; // can't add crates to kg
        $row['base_qty'] = toBaseQuantity($row['quantity'], $row['unit']);
        $candidates[] = $row;
    }
    $stmt->close();

    return $candidates;
}

/**
 * Everything the buyer's page needs for one demand: candidate listings (flagged when part of the
 * suggested bundle) plus required / accepted / pool quantities in the DEMAND's unit.
 */
function getDemandCoverage($conn, $demandId) {
    $stmt = $conn->prepare("SELECT * FROM demands WHERE demand_id = ?");
    $stmt->bind_param('i', $demandId);
    $stmt->execute();
    $demand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$demand) return null;

    $unit         = $demand['unit'];
    $requiredBase = toBaseQuantity($demand['min_quantity'], $unit);
    $acceptedBase = getAcceptedBaseQuantity($conn, $demand);
    $remaining    = max(0, $requiredBase - $acceptedBase);

    $candidates = getCandidateListings($conn, $demand);

    // Pool = listings still in play (declined ones are excluded from the maths)
    $pool = array_values(array_filter($candidates, function ($c) {
        return $c['match_status'] !== 'rejected';
    }));
    usort($pool, function ($a, $b) {
        return $b['base_qty'] <=> $a['base_qty'];
    });

    $poolBase = 0.0;
    foreach ($pool as $p) {
        $poolBase += $p['base_qty'];
    }

    // Suggested bundle: largest-first until the remaining need is covered
    $bundleIds   = [];
    $bundleTotal = 0.0;
    foreach ($pool as $p) {
        if ($bundleTotal + 0.0001 >= $remaining) break;
        $bundleIds[$p['listing_id']] = true;
        $bundleTotal += $p['base_qty'];
    }

    foreach ($candidates as &$c) {
        $c['in_bundle'] = isset($bundleIds[$c['listing_id']]);
    }
    unset($c);

    // Order: suggested bundle first, then largest; declined listings last
    usort($candidates, function ($a, $b) {
        $ra = $a['match_status'] === 'rejected' ? 1 : 0;
        $rb = $b['match_status'] === 'rejected' ? 1 : 0;
        if ($ra !== $rb) return $ra <=> $rb;
        if ($a['in_bundle'] !== $b['in_bundle']) return $b['in_bundle'] <=> $a['in_bundle'];
        return $b['base_qty'] <=> $a['base_qty'];
    });

    $potentialBase = $acceptedBase + $poolBase;
    $acceptedPct   = $requiredBase > 0 ? min(100, ($acceptedBase / $requiredBase) * 100) : 0;
    $potentialPct  = $requiredBase > 0 ? min(100, ($potentialBase / $requiredBase) * 100) : 0;

    return [
        'demand'         => $demand,
        'unit'           => $unit,
        'required'       => fromBaseQuantity($requiredBase, $unit),
        'accepted_qty'   => fromBaseQuantity($acceptedBase, $unit),
        'pool_qty'       => fromBaseQuantity($poolBase, $unit),
        'shortfall'      => fromBaseQuantity(max(0, $requiredBase - $potentialBase), $unit),
        'fulfillable'    => ($potentialBase + 0.0001) >= $requiredBase,
        'fully_accepted' => ($acceptedBase + 0.0001) >= $requiredBase,
        'accepted_pct'   => $acceptedBase > 0 ? $acceptedPct : 0,
        'potential_pct'  => $potentialPct,
        'bundle_count'   => count($bundleIds),
        'listings'       => $candidates,
    ];
}

/**
 * Backwards-compatible wrapper: flat list of matching listings for a demand.
 */
function getMatchesForDemand($conn, $demandId) {
    $coverage = getDemandCoverage($conn, $demandId);
    return $coverage ? $coverage['listings'] : [];
}

// ---------- FARMER SIDE: CANDIDATE DEMANDS ----------
/**
 * Open demands for the same crop. Location and individual size don't matter:
 * a small listing can still contribute toward a larger demand.
 * Adds how much of each demand is confirmed/remaining and how much of the
 * remaining need this listing could cover. No buyer contact details are selected.
 */
function getListingCandidates($conn, $listing) {
    $sql = "
        SELECT d.*, ub.full_name AS buyer_name, b.organization_type AS buyer_org_type,
               mt.match_id, mt.status AS match_status, mt.requested_by
        FROM demands d
        JOIN buyer_details b ON b.buyer_id = d.buyer_id
        JOIN users ub ON ub.user_id = b.user_id
        LEFT JOIN matches mt ON mt.demand_id = d.demand_id AND mt.listing_id = ?
        WHERE d.status = 'open'
          AND d.crop_type = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $listing['listing_id'], $listing['crop_type']);
    $stmt->execute();
    $result = $stmt->get_result();

    $listingFamily = unitFamily($listing['unit']);
    $listingBase   = toBaseQuantity($listing['quantity'], $listing['unit']);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        if (unitFamily($row['unit']) !== $listingFamily) continue;

        $requiredBase  = toBaseQuantity($row['min_quantity'], $row['unit']);
        $acceptedBase  = getAcceptedBaseQuantity($conn, $row);
        $remainingBase = max(0, $requiredBase - $acceptedBase);
        if ($remainingBase <= 0.0001) continue; // already fully covered

        $row['required_qty']  = fromBaseQuantity($requiredBase, $row['unit']);
        $row['accepted_qty']  = fromBaseQuantity($acceptedBase, $row['unit']);
        $row['remaining_qty'] = fromBaseQuantity($remainingBase, $row['unit']);
        $row['accepted_pct']  = $requiredBase > 0 ? min(100, ($acceptedBase / $requiredBase) * 100) : 0;
        $row['covers_pct']    = min(100, ($listingBase / $remainingBase) * 100);
        $rows[] = $row;
    }
    $stmt->close();

    // Declined last, then biggest remaining need first
    usort($rows, function ($a, $b) {
        $ra = $a['match_status'] === 'rejected' ? 1 : 0;
        $rb = $b['match_status'] === 'rejected' ? 1 : 0;
        if ($ra !== $rb) return $ra <=> $rb;
        return $b['remaining_qty'] <=> $a['remaining_qty'];
    });

    return $rows;
}

/**
 * Buyer requests that are waiting for THIS farmer's answer (no buyer contact details).
 */
function getIncomingRequestsForFarmer($conn, $farmerId) {
    $stmt = $conn->prepare("
        SELECT m.match_id, m.match_date,
               p.crop_type, p.variety, p.quantity AS listing_quantity, p.unit AS listing_unit, p.photo,
               d.min_quantity, d.unit AS demand_unit, d.preferred_quality, d.max_price_per_unit,
               d.preferred_location, d.needed_by, d.frequency, d.description AS demand_description,
               ub.full_name AS buyer_name, b.organization_type AS buyer_org_type
        FROM matches m
        JOIN produce_listings p ON p.listing_id = m.listing_id
        JOIN demands d ON d.demand_id = m.demand_id
        JOIN buyer_details b ON b.buyer_id = d.buyer_id
        JOIN users ub ON ub.user_id = b.user_id
        WHERE p.farmer_id = ?
          AND m.status = 'proposed'
          AND m.requested_by = 'buyer'
          AND p.status = 'available'
          AND d.status = 'open'
        ORDER BY m.match_date DESC
    ");
    $stmt->bind_param('i', $farmerId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

// ---------- CREATING AND ANSWERING REQUESTS ----------
/**
 * Either side can start a match request.
 * $actorRole = 'farmer' | 'buyer'; $actorProfileId = that user's farmer_id / buyer_id.
 * Returns ['ok' => bool, 'error' => string, 'listing' => row, 'demand' => row].
 */
function createMatchRequest($conn, $listingId, $demandId, $actorRole, $actorProfileId) {
    if (!in_array($actorRole, ['farmer', 'buyer'], true)) {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }

    $listing = fetchListingInternal($conn, $listingId);
    $demand  = fetchDemandInternal($conn, $demandId);

    if (!$listing || !$demand) {
        return ['ok' => false, 'error' => 'That produce listing or demand no longer exists.'];
    }

    // The actor must own their side of the match
    if ($actorRole === 'farmer' && (int) $listing['farmer_id'] !== (int) $actorProfileId) {
        return ['ok' => false, 'error' => 'You can only request matches for your own produce.'];
    }
    if ($actorRole === 'buyer' && (int) $demand['buyer_id'] !== (int) $actorProfileId) {
        return ['ok' => false, 'error' => 'You can only request matches for your own demands.'];
    }

    if ($listing['status'] !== 'available') {
        return ['ok' => false, 'error' => 'This produce is no longer available.'];
    }
    if ($demand['status'] !== 'open') {
        return ['ok' => false, 'error' => 'This demand is no longer open.'];
    }
    if (strcasecmp($listing['crop_type'], $demand['crop_type']) !== 0) {
        return ['ok' => false, 'error' => 'Crop types do not match.'];
    }
    if (unitFamily($listing['unit']) !== unitFamily($demand['unit'])) {
        return ['ok' => false, 'error' => 'The units of measurement are not compatible.'];
    }
    if (getMatchStatus($conn, $listingId, $demandId) !== null) {
        return ['ok' => false, 'error' => 'A request already exists between this produce and demand.'];
    }

    $stmt = $conn->prepare("
        INSERT INTO matches (listing_id, demand_id, requested_by, status)
        VALUES (?, ?, ?, 'proposed')
        ON DUPLICATE KEY UPDATE match_id = match_id
    ");
    $stmt->bind_param('iis', $listingId, $demandId, $actorRole);
    $stmt->execute();
    $stmt->close();

    return ['ok' => true, 'listing' => $listing, 'demand' => $demand];
}

/**
 * The party that did NOT start the request accepts or declines it.
 * On acceptance the listing is marked 'matched' and the demand only becomes 'matched'
 * once accepted farmers' combined quantity covers it.
 * Returns ['ok', 'error'] or ['ok', 'action', 'match', 'listing', 'demand'].
 */
function respondToMatch($conn, $matchId, $action, $actorRole, $actorProfileId) {
    if (!in_array($action, ['accept', 'reject'], true) || !in_array($actorRole, ['farmer', 'buyer'], true)) {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }

    $stmt = $conn->prepare("SELECT * FROM matches WHERE match_id = ?");
    $stmt->bind_param('i', $matchId);
    $stmt->execute();
    $match = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$match) {
        return ['ok' => false, 'error' => 'Match request not found.'];
    }
    if ($match['status'] !== 'proposed') {
        return ['ok' => false, 'error' => 'This request has already been answered.'];
    }
    if ($match['requested_by'] === $actorRole) {
        return ['ok' => false, 'error' => 'You cannot respond to your own request. Wait for the other party.'];
    }

    $listing = fetchListingInternal($conn, $match['listing_id']);
    $demand  = fetchDemandInternal($conn, $match['demand_id']);
    if (!$listing || !$demand) {
        return ['ok' => false, 'error' => 'The produce or demand no longer exists.'];
    }

    if ($actorRole === 'farmer' && (int) $listing['farmer_id'] !== (int) $actorProfileId) {
        return ['ok' => false, 'error' => 'This request does not belong to you.'];
    }
    if ($actorRole === 'buyer' && (int) $demand['buyer_id'] !== (int) $actorProfileId) {
        return ['ok' => false, 'error' => 'This request does not belong to you.'];
    }

    if ($action === 'accept') {
        if ($listing['status'] !== 'available') {
            return ['ok' => false, 'error' => 'This produce is no longer available, so the match cannot be accepted.'];
        }
        if ($demand['status'] !== 'open') {
            return ['ok' => false, 'error' => 'This demand is already fully covered, so the match cannot be accepted.'];
        }
    }

    $conn->begin_transaction();
    try {
        $newStatus = $action === 'accept' ? 'accepted' : 'rejected';
        $stmt = $conn->prepare("UPDATE matches SET status = ? WHERE match_id = ?");
        $stmt->bind_param('si', $newStatus, $matchId);
        $stmt->execute();
        $stmt->close();

        if ($action === 'accept') {
            $stmt = $conn->prepare("UPDATE produce_listings SET status = 'matched' WHERE listing_id = ?");
            $stmt->bind_param('i', $match['listing_id']);
            $stmt->execute();
            $stmt->close();

            refreshDemandStatus($conn, $match['demand_id']);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Could not update the match. Please try again.'];
    }

    return ['ok' => true, 'action' => $action, 'match' => $match, 'listing' => $listing, 'demand' => $demand];
}

// ---------- DEMAND STATUS ----------
/**
 * Sets a demand to 'matched' only when accepted farmers' combined quantity covers it;
 * otherwise keeps it 'open'. Leaves 'fulfilled' / 'closed' demands untouched.
 */
function refreshDemandStatus($conn, $demandId) {
    $stmt = $conn->prepare("SELECT * FROM demands WHERE demand_id = ?");
    $stmt->bind_param('i', $demandId);
    $stmt->execute();
    $demand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$demand) return null;
    if (!in_array($demand['status'], ['open', 'matched'])) return $demand['status'];

    $requiredBase = toBaseQuantity($demand['min_quantity'], $demand['unit']);
    $acceptedBase = getAcceptedBaseQuantity($conn, $demand);
    $newStatus    = ($acceptedBase + 0.0001) >= $requiredBase ? 'matched' : 'open';

    if ($newStatus !== $demand['status']) {
        $stmt = $conn->prepare("UPDATE demands SET status = ? WHERE demand_id = ?");
        $stmt->bind_param('si', $newStatus, $demandId);
        $stmt->execute();
        $stmt->close();
    }
    return $newStatus;
}

// ---------- HELPERS ----------
function getMatchStatus($conn, $listingId, $demandId) {
    $stmt = $conn->prepare("SELECT status FROM matches WHERE listing_id = ? AND demand_id = ?");
    $stmt->bind_param('ii', $listingId, $demandId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['status'] : null;
}

// ---------- ACCEPTED MATCHES (the ONLY functions that return contact details) ----------
/**
 * Accepted farmers for a demand, WITH farmer contact info (buyer's view).
 */
function getAcceptedMatchForDemand($conn, $demandId) {
    $stmt = $conn->prepare("
        SELECT m.match_id, p.listing_id, p.crop_type, p.variety, p.quantity, p.unit,
               p.quality_grade, p.price_per_unit, p.location, p.photo, p.description,
               p.harvest_date, p.available_from, p.available_until,
               u.full_name AS farmer_name, u.phone AS farmer_phone, u.email AS farmer_email
        FROM matches m
        JOIN produce_listings p ON p.listing_id = m.listing_id
        JOIN farmer_details f ON f.farmer_id = p.farmer_id
        JOIN users u ON u.user_id = f.user_id
        WHERE m.demand_id = ? AND m.status = 'accepted'
    ");
    $stmt->bind_param('i', $demandId);
    $stmt->execute();
    $result = $stmt->get_result();

    $accepted = [];
    while ($row = $result->fetch_assoc()) {
        $accepted[] = $row;
    }
    $stmt->close();

    return $accepted;
}

/**
 * Accepted matches for a farmer, WITH buyer contact info (farmer's view).
 */
function getAcceptedMatchesForFarmer($conn, $farmerId) {
    $stmt = $conn->prepare("
        SELECT m.match_id, m.match_date,
               p.crop_type, p.variety, p.quantity AS listing_quantity, p.unit AS listing_unit,
               d.min_quantity, d.unit AS demand_unit, d.preferred_location, d.needed_by, d.frequency,
               ub.full_name AS buyer_name, ub.phone AS buyer_phone, ub.email AS buyer_email,
               b.physical_address AS buyer_address, b.organization_type AS buyer_org_type
        FROM matches m
        JOIN produce_listings p ON p.listing_id = m.listing_id
        JOIN demands d ON d.demand_id = m.demand_id
        JOIN buyer_details b ON b.buyer_id = d.buyer_id
        JOIN users ub ON ub.user_id = b.user_id
        WHERE p.farmer_id = ? AND m.status = 'accepted'
        ORDER BY m.match_date DESC
    ");
    $stmt->bind_param('i', $farmerId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}