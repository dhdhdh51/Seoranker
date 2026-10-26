-- Listing review provenance (backs the "Listing Reviewed" badge) + public listing index.
ALTER TABLE listings ADD COLUMN reviewed_at DATETIME NULL AFTER moderation_note, ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER reviewed_at;
CREATE INDEX idx_listing_public ON listings (status, visibility, published_at);
