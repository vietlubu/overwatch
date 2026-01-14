const express = require("express");
const zlib = require("zlib");

const app = express();

// Auth endpoint: validate refresh token and return ingest details
app.post("/api/agent-auth", express.json(), (req, res) => {
    const auth = req.headers["authorization"] || "";
    const refreshToken = auth.startsWith("Bearer ") ? auth.slice(7) : "";
    const EXPECTED_REFRESH_TOKEN = process.env.NIGHTWATCH_TOKEN || "dev-token";

    if (!refreshToken) {
        return res.status(401).json({ message: "Missing refresh token" });
    }
    if (refreshToken !== EXPECTED_REFRESH_TOKEN) {
        return res.status(403).json({ message: "Invalid refresh token" });
    }

    // Return access token + ingest_url for the agent to POST data to
    return res.json({
        token: "ingest-access-token",
        expires_in: 3600,
        refresh_in: 300,
        ingest_url: "http://localhost:3000/api/ingest",
    });
});

// Ingest endpoint: accept gzip payload, decompress it, and log the result
app.post("/api/ingest", (req, res) => {
    const auth = req.headers["authorization"] || "";
    const accessToken = auth.startsWith("Bearer ") ? auth.slice(7) : "";
    if (accessToken !== "ingest-access-token") {
        return res.status(401).json({ message: "Bad ingest token" });
    }

    const chunks = [];
    req.on("data", (c) => chunks.push(c));
    req.on("end", () => {
        const buf = Buffer.concat(chunks);
        const enc = (req.headers["content-encoding"] || "").toLowerCase();

        const handleBody = (err, body) => {
            if (err) {
                console.error("Gunzip error:", err);
                return res.status(400).json({ message: "Bad gzip" });
            }
            try {
                const text = body.toString("utf8");
                console.log("Ingest payload:", text);
                // text is a JSON array of Nightwatch records
                // TODO: parse/store as needed
                return res.status(200).json({ message: "ok" });
            } catch (e) {
                console.error("Parse error:", e);
                return res.status(400).json({ message: "Bad payload" });
            }
        };

        if (enc === "gzip") {
            zlib.gunzip(buf, handleBody);
        } else {
            handleBody(null, buf);
        }
    });
});

app.listen(3000, () => {
    console.log("Mock Nightwatch server listening on http://localhost:3000");
});
