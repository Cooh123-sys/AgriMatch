<?php
/**
 * AgriMatch Matching Engine (aggregation-enabled)
 *
 *   Level 1: Crop type  (fixed filter)
 *   Level 2: Location   (loose match on stored location text)
 *   Level 3: Quantity   (AGGREGATE: combined supply of matching farmers
 *                        must reach the buyer's required quantity)
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

// ---------- CANDIDATE LISTINGS (Levels 1 + 2) ----------
/**
 * All available listings matching the demand's crop and location,
 * regardless of individual size. Includes each listing's existing match
 * status for this demand (null / proposed / rejected).
 */
function getCandidateListings($conn, $demand) {
    $sql = "
        SELECT p.*, u.full_name AS farmer_name, u.phone AS farmer_phone, u.email AS farmer_email,
               mt.status AS match_status
        FROM produce_listings p
        JOIN farmer_details f ON f.farmer_id = p.farmer_id
        JOIN users u ON u.user_id = f.user_id
        LEFT JOIN matches mt ON mt.listing_id = p.listing_id AND mt.demand_id = ?
        WHERE p.status = 'available'
          AND p.crop_type = ?
          AND (
                LOWER(p.location) LIKE CONCAT('%', LOWER(?), '%')
             OR LOWER(?) LIKE CONCAT('%', LOWER(p.location), '%')
          )
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'isss',
        $demand['demand_id'],
        $demand['crop_type'],
        $demand['preferred_location'],
        $demand['preferred_location']
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $family = unitFamily($demand['unit']);
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        // Skip listings whose unit can't be added to the demand's unit
        if (unitFamily($row['unit']) !== $family) continue;
        $row['base_qty'] = toBaseQuantity($row['quantity'], $row['unit']);
        $candidates[] = $row;
    }
    $stmt->close();

    return $candidates;
}

/**
 * Total quantity (in base units) already committed through ACCEPTED matches.
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

// ---------- AGGREGATE COVERAGE (Level 3) ----------
/**
 * Core aggregation function. Returns everything the UI needs for one demand:
 *   - all candidate listings (flagged in_bundle when part of the suggested bundle)
 *   - required / accepted / pool quantities, all expressed in the DEMAND's unit
 *   - whether combined supply can fulfil the demand
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

    // Order: suggested bundle first, then largest, declined listings last
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
        'accepted_pct'   => $acceptedPct,
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

// ---------- DEMAND STATUS ----------
/**
 * Sets a demand to 'matched' only when accepted farmers' combined quantity
 * covers the requirement; otherwise keeps it 'open' so more farmers can join.
 * Leaves 'fulfilled' / 'closed' demands untouched.
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

// ---------- FARMER-SIDE VIEW ----------
/**
 * Given a produce listing, find open demands in the same crop and area.
 * Quantity is no longer a barrier: a small listing can still contribute
 * toward a larger demand.
 */
function getMatchesForListing($conn, $listingId) {
    $stmt = $conn->prepare("SELECT * FROM produce_listings WHERE listing_id = ?");
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$listing) return [];

    $sql = "
        SELECT d.*, u.full_name AS buyer_name, u.phone AS buyer_phone, u.email AS buyer_email
        FROM demands d
        JOIN buyer_details b ON b.buyer_id = d.buyer_id
        JOIN users u ON u.user_id = b.user_id
        WHERE d.status = 'open'
          AND d.crop_type = ?
          AND (
                LOWER(d.preferred_location) LIKE CONCAT('%', LOWER(?), '%')
             OR LOWER(?) LIKE CONCAT('%', LOWER(d.preferred_location), '%')
          )
        ORDER BY d.min_quantity DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $listing['crop_type'], $listing['location'], $listing['location']);
    $stmt->execute();
    $result = $stmt->get_result();

    $family = unitFamily($listing['unit']);
    $matches = [];
    while ($row = $result->fetch_assoc()) {
        if (unitFamily($row['unit']) !== $family) continue;
        $matches[] = $row;
    }
    $stmt->close();

    return $matches;
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

/**
 * Accepted matches (with farmer contact info) for a demand. Bypasses the
 * 'available' filter, since accepted listings are moved to 'matched'.
 */
function getAcceptedMatchForDemand($conn, $demandId) {
    $stmt = $conn->prepare("
        SELECT m.match_id, p.listing_id, p.crop_type, p.variety, p.quantity, p.unit,
               p.quality_grade, p.price_per_unit, p.location,
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