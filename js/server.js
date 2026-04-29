const express = require('express');
const cors = require('cors');
const path = require('path');
const fs = require('fs').promises;
const sqlite3 = require('sqlite3');
const chokidar = require('chokidar');

const app = express();
app.use(express.json({ limit: '50mb' }));
app.use(cors());
app.use(express.static(path.join(__dirname, '../frontend')));

// Database setup
const db = new sqlite3.Database('./database/anpr.db');

db.serialize(() => {
  db.run(`
    CREATE TABLE IF NOT EXISTS plates (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      plate_number TEXT NOT NULL,
      date_detected DATETIME DEFAULT CURRENT_TIMESTAMP,
      image_path TEXT,
      folder_path TEXT,
      UNIQUE(plate_number, date_detected)
    )
  `);

  db.run(`
    CREATE TABLE IF NOT EXISTS tickets (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      plate_id INTEGER,
      barcode TEXT,
      ticket_number TEXT,
      ticket_data TEXT,
      ticket_time DATETIME,
      user_notes TEXT,
      payment_status TEXT,
      vehicle_type TEXT,
      vehicle_color TEXT,
      vehicle_model TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY(plate_id) REFERENCES plates(id)
    )
  `);
});

// Folder Scanner Service
const scanFolder = async (basePath) => {
  const results = [];
  
  try {
    const dates = await fs.readdir(basePath);
    
    for (const dateFolder of dates) {
      const datePath = path.join(basePath, dateFolder);
      const stat = await fs.stat(datePath);
      
      if (!stat.isDirectory()) continue;
      
      // Controlla formato data: YYYY-MM-DD
      if (!/^\d{4}-\d{2}-\d{2}$/.test(dateFolder)) continue;
      
      const hours = await fs.readdir(datePath).catch(() => []);
      
      for (const hourFolder of hours) {
        const hourPath = path.join(datePath, hourFolder);
        const hourStat = await fs.stat(hourPath).catch(() => null);
        
        if (!hourStat?.isDirectory()) continue;
        
        const minutes = await fs.readdir(hourPath).catch(() => []);
        
        for (const minuteFolder of minutes) {
          const minutePath = path.join(hourPath, minuteFolder);
          const minuteStat = await fs.stat(minutePath).catch(() => null);
          
          if (!minuteStat?.isDirectory()) continue;
          
          const files = await fs.readdir(minutePath).catch(() => []);
          
          for (const file of files) {
            if (/\.jpg$/i.test(file)) {
              const match = file.match(/(\d{2})-(\d{2})-(\d{4})-(\d{2})-(\d{2})-([A-Z0-9]+)-ANPR/);
              
              if (match) {
                const [, day, month, year, hour, minute, plate] = match;
                results.push({
                  plate: plate,
                  date: `${year}-${month}-${day}`,
                  time: `${hour}:${minute}`,
                  timestamp: `${year}-${month}-${day}T${hour}:${minute}:00`,
                  image_path: path.join(dateFolder, hourFolder, minuteFolder, file),
                  full_path: path.join(minutePath, file)
                });
              }
            }
          }
        }
      }
    }
  } catch (error) {
    console.error('Errore scansione cartelle:', error);
  }
  
  return results;
};

// API Endpoints
app.get('/api/plates', async (req, res) => {
  const { days = 10, plate, date, time, sort = 'asc' } = req.query;
  
  let query = 'SELECT * FROM plates WHERE 1=1';
  const params = [];
  
  if (days) {
    query += ` AND date_detected >= datetime('now', '-${parseInt(days)} days')`;
  }
  
  if (plate) {
    query += ' AND plate_number LIKE ?';
    params.push(`%${plate}%`);
  }
  
  if (date) {
    query += ' AND DATE(date_detected) = ?';
    params.push(date);
  }
  
  if (time) {
    query += ' AND strftime("%H:%M", date_detected) = ?';
    params.push(time);
  }
  
  query += sort === 'desc' 
    ? ' ORDER BY date_detected DESC' 
    : ' ORDER BY date_detected ASC';
  
  db.all(query, params, (err, rows) => {
    if (err) return res.status(500).json({ error: err.message });
    res.json(rows || []);
  });
});

app.post('/api/plates', async (req, res) => {
  const { plate_number, date_detected, image_path, folder_path } = req.body;
  
  db.run(
    `INSERT OR IGNORE INTO plates (plate_number, date_detected, image_path, folder_path)
     VALUES (?, ?, ?, ?)`,
    [plate_number, date_detected, image_path, folder_path],
    (err) => {
      if (err) return res.status(500).json({ error: err.message });
      res.json({ success: true });
    }
  );
});

app.get('/api/tickets/:plate_id', (req, res) => {
  const { plate_id } = req.params;
  
  db.all(
    'SELECT * FROM tickets WHERE plate_id = ? ORDER BY created_at DESC',
    [plate_id],
    (err, rows) => {
      if (err) return res.status(500).json({ error: err.message });
      res.json(rows || []);
    }
  );
});

app.post('/api/tickets', (req, res) => {
  const {
    plate_id,
    barcode,
    ticket_number,
    ticket_data,
    ticket_time,
    user_notes,
    payment_status,
    vehicle_type,
    vehicle_color,
    vehicle_model
  } = req.body;
  
  db.run(
    `INSERT INTO tickets 
     (plate_id, barcode, ticket_number, ticket_data, ticket_time, user_notes, 
      payment_status, vehicle_type, vehicle_color, vehicle_model)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [plate_id, barcode, ticket_number, ticket_data, ticket_time, user_notes,
     payment_status, vehicle_type, vehicle_color, vehicle_model],
    function(err) {
      if (err) return res.status(500).json({ error: err.message });
      res.json({ id: this.lastID, success: true });
    }
  );
});

app.put('/api/tickets/:id', (req, res) => {
  const { id } = req.params;
  const updates = req.body;
  
  const setClause = Object.keys(updates).map(k => `${k} = ?`).join(', ');
  const values = Object.values(updates);
  
  db.run(
    `UPDATE tickets SET ${setClause} WHERE id = ?`,
    [...values, id],
    (err) => {
      if (err) return res.status(500).json({ error: err.message });
      res.json({ success: true });
    }
  );
});

app.get('/api/image/:id', (req, res) => {
  const { id } = req.params;
  
  db.get('SELECT image_path FROM plates WHERE id = ?', [id], (err, row) => {
    if (err || !row) return res.status(404).json({ error: 'Non trovato' });
    
    const imagePath = path.join('/monitored-folder', row.image_path);
    res.sendFile(imagePath);
  });
});

// Monitora cartelle ogni secondo
const MONITORED_FOLDER = process.env.ANPR_FOLDER || './monitored-folder';
const SCAN_INTERVAL = 1000; // 1 secondo

setInterval(async () => {
  const plates = await scanFolder(MONITORED_FOLDER);
  
  for (const p of plates) {
    db.run(
      `INSERT OR IGNORE INTO plates (plate_number, date_detected, image_path)
       VALUES (?, ?, ?)`,
      [p.plate, p.timestamp, p.image_path]
    );
  }
}, SCAN_INTERVAL);

app.listen(3000, () => console.log('Server avviato su http://localhost:3000'));