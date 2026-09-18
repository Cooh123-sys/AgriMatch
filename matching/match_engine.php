<?php
/**
 * AgriMatch Matching Engine
 * Implements the 3-level matching algorithm described in the project proposal:
 *   Level 1: Crop type   (fixed filter)
 *   Level 2: Quantity    (listing must meet or exceed the buyer's minimum)
 *   Level 3: Location    (loose match on stored location text)
 */

/**
 * Given a demand, find all available produce listings that satisfy all 3 levels.
 */
function getMatchesForDemand($conn, $demandId) {
    $stmt = $conn->prepare("SELECT * FROM demands WHERE demand_id = ?");
    $stmt->bind_param('i', $demandId);
    $stmt->execute();
    $demand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$demand) return [];

    // Level 1 (crop_type) + Level 2 (quantity) done in SQL;
    // Level 3 (location) uses a loose two-way substring match since
    // farmers/buyers type free-text locations that rarely match exactly.
    $sql = "
        SELECT p.*, u.full_name AS farmer_name, u.phone AS farmer_phone, u.email AS farmer_email
        FROM produce_listings p
        JOIN farmer_details f ON f.farmer_id = p.farmer_id
        JOIN users u ON u.user_id = f.user_id
        WHERE p.status = 'available'
          AND p.crop_type = ?
          AND p.quantity >= ?
          AND (
                LOWER(p.location) LIKE CONCAT('%', LOWER(?), '%')
             OR LOWER(?) LIKE CONCAT('%', LOWER(p.location), '%')
          )
        ORDER BY p.quantity DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'sdss',
        $demand['crop_type'],
        $demand['min_quantity'],
        $demand['preferred_location'],
        $demand['preferred_location']
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $matches = [];
    while ($row = $result->fetch_assoc()) {
        $matches[] = $row;
    }
    $stmt->close();

    return $matches;
}

/**
 * Given a produce listing, find all open demands that it satisfies.
 * (Same 3 levels, viewed from the farmer's side.)
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
          AND d.min_quantity <= ?
          AND (
                LOWER(d.preferred_location) LIKE CONCAT('%', LOWER(?), '%')
             OR LOWER(?) LIKE CONCAT('%', LOWER(d.preferred_location), '%')
          )
        ORDER BY d.min_quantity DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'sdss',
        $listing['crop_type'],
        $listing['quantity'],
        $listing['location'],
        $listing['location']
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $matches = [];
    while ($row = $result->fetch_assoc()) {
        $matches[] = $row;
    }
    $stmt->close();

    return $matches;
}

/**
 * Returns the existing match status between a listing and a demand, or null if none exists yet.
 */
function getMatchStatus($conn, $listingId, $demandId) {
    $stmt = $conn->prepare("SELECT status FROM matches WHERE listing_id = ? AND demand_id = ?");
    $stmt->bind_param('ii', $listingId, $demandId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['status'] : null;
}

/**
 * Returns the accepted match (with farmer contact info) for a given demand, if one exists.
 * Bypasses the "available" status filter, since an accepted listing is intentionally
 * moved to 'matched' status and would otherwise vanish from getMatchesForDemand().
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